<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Dto\PowerContext;
use App\Scoring\Rules\FieldDay2025;

uses()->group('unit', 'scoring');

beforeEach(function () {
    $this->fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $this->rules = new FieldDay2025;
});

test('identifiers', function () {
    expect($this->rules->id())->toBe('FD-2025')
        ->and($this->rules->version())->toBe('2025')
        ->and($this->rules->eventTypeCode())->toBe('FD');
});

test('gota flat points and coach threshold', function () {
    expect($this->rules->gotaPointsPerContact())->toBe(5)
        ->and($this->rules->gotaCoachThreshold())->toBe(10)
        ->and($this->rules->gotaCoachBonus())->toBe(100);
});

test('youth and emergency constants', function () {
    expect($this->rules->youthMaxCount())->toBe(5)
        ->and($this->rules->youthPointsPerYouth())->toBe(20)
        ->and($this->rules->emergencyPowerMaxTransmitters())->toBe(20);
});

test('power multiplier: over 100W = 1x', function () {
    $ctx = new PowerContext(effectivePowerWatts: 150, qualifiesForQrpNaturalBonus: true);
    expect($this->rules->powerMultiplier($ctx))->toBe('1');
});

test('power multiplier: QRP + natural = 5x', function () {
    $ctx = new PowerContext(effectivePowerWatts: 5, qualifiesForQrpNaturalBonus: true);
    expect($this->rules->powerMultiplier($ctx))->toBe('5');
});

test('power multiplier: QRP without natural bonus = 2x', function () {
    $ctx = new PowerContext(effectivePowerWatts: 5, qualifiesForQrpNaturalBonus: false);
    expect($this->rules->powerMultiplier($ctx))->toBe('2');
});

test('power multiplier: 6-100W = 2x', function () {
    $ctx = new PowerContext(effectivePowerWatts: 50, qualifiesForQrpNaturalBonus: true);
    expect($this->rules->powerMultiplier($ctx))->toBe('2');
});

test('pointsForContact: GOTA station contacts score by mode like any other', function () {
    $mode = Mode::factory()->create(['points_fd' => 2]);
    $station = Station::factory()->gota()->make();

    expect($this->rules->pointsForContact($mode, $station))->toBe(2);
});

test('pointsForContact: non-GOTA falls back to modes.points_fd', function () {
    $mode = Mode::factory()->create(['points_fd' => 2]);
    $station = Station::factory()->make();

    expect($this->rules->pointsForContact($mode, $station))->toBe(2);
});

test('pointsForContact: non-GOTA prefers mode_rule_points override when present', function () {
    $mode = Mode::factory()->create(['points_fd' => 2]);
    ModeRulePoint::create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'mode_id' => $mode->id,
        'points' => 7,
    ]);
    $station = Station::factory()->make();

    expect($this->rules->pointsForContact($mode, $station))->toBe(7);
});

test('bonus() returns the 2025-scoped row only', function () {
    BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'code' => 'widget_bonus',
        'base_points' => 42,
    ]);
    BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2026',
        'code' => 'widget_bonus',
        'base_points' => 99,
    ]);

    $row = $this->rules->bonus('widget_bonus');

    expect($row)->not->toBeNull()
        ->and($row->base_points)->toBe(42);
});

test('bonus() returns null for unknown code', function () {
    expect($this->rules->bonus('no_such_code'))->toBeNull();
});

test('bonusRuleReference returns ARRL section and text for every seeded code', function () {
    $codes = [
        'emergency_power', 'media_publicity', 'public_location', 'public_info_booth',
        'sm_sec_message', 'nts_message', 'satellite_qso', 'natural_power',
        'w1aw_bulletin', 'educational_activity', 'elected_official_visit',
        'agency_visit', 'web_submission', 'youth_participation', 'social_media',
        'safety_officer', 'site_responsibilities',
    ];

    foreach ($codes as $code) {
        $ref = $this->rules->bonusRuleReference($code);
        expect($ref)->not->toBeNull("no rule text for {$code}")
            ->and($ref)->toHaveKeys(['section', 'text'])
            ->and($ref['section'])->toStartWith('7.3.')
            ->and($ref['text'])->not->toBe('');
    }
});

test('bonusRuleReference covers GOTA synthetic codes', function () {
    expect($this->rules->bonusRuleReference('gota_qso')['section'])->toBe('7.3.13.1')
        ->and($this->rules->bonusRuleReference('gota_coach')['section'])->toBe('7.3.13.2');
});

test('bonusRuleReference returns null for unknown code', function () {
    expect($this->rules->bonusRuleReference('no_such_code'))->toBeNull();
});

test('score composition is unchanged by the ruleset contract split', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'max_power_watts' => 100,
        'has_gota_station' => false,
    ]);

    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_fd' => 2]);

    Contact::factory()->count(4)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_gota_contact' => false,
    ]);

    $breakdown = $config->scoreBreakdown();

    // 8 QSO points at the 2x low-power multiplier, still additive with bonuses.
    expect($breakdown->value('qso_points'))->toBe(8)
        ->and($breakdown->value('power_multiplier'))->toBe(2)
        ->and($breakdown->value('qso_score'))->toBe(16)
        ->and($breakdown->formula)->toBe('(QSO Points x Power Multiplier) + GOTA + Bonus Points')
        ->and($breakdown->total)->toBe($config->calculateQsoScore() + $config->calculateBonusScore());
});

test('Field Day still reports a power multiplier and Bonus wording', function () {
    expect((new FieldDay2025)->nomenclature()->awardPlural)->toBe('Bonuses')
        ->and((new FieldDay2025)->nomenclature()->awardSectionTitle)->toBe('Bonus Points')
        ->and((new FieldDay2025)->nomenclature()->rulebookName)->toBe('ARRL')
        ->and((new FieldDay2025)->nomenclature()->bulletinName)->toBe('W1AW Bulletin')
        ->and((new FieldDay2025)->nomenclature()->bulletinSources)->toBe(['W1AW', 'K6KPH']);
});
