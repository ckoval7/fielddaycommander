<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Rules\WinterFieldDay2026;
use Database\Seeders\BonusTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

/**
 * Every assertion here is taken from the WFDA Standard Operating Procedure
 * (2026 edition), https://winterfieldday.org/sop.php.
 */
beforeEach(function () {
    $this->wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $this->rules = new WinterFieldDay2026;
});

/**
 * A WFD event configuration with its objectives seeded, as the migration does.
 */
function wfdConfig(EventType $wfd, array $configAttributes = []): EventConfiguration
{
    $event = Event::factory()->create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2026',
    ]);

    return EventConfiguration::factory()->create([
        'event_id' => $event->id,
        ...$configAttributes,
    ]);
}

function wfdObjective(EventType $wfd, string $code, int $multiplier): BonusType
{
    return BonusType::create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2026',
        'code' => $code,
        'name' => $code,
        'trigger_type' => 'manual',
        'base_points' => 0,
        'objective_multiplier' => $multiplier,
        'max_occurrences' => 1,
        'is_active' => true,
    ]);
}

function achieveObjective(EventConfiguration $config, BonusType $objective): EventBonus
{
    return EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $objective->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
        'verified_at' => now(),
    ]);
}

test('identifiers', function () {
    expect($this->rules->id())->toBe('WFD-2026')
        ->and($this->rules->version())->toBe('2026')
        ->and($this->rules->eventTypeCode())->toBe('WFD');
});

test('is not a Field Day ruleset', function () {
    expect($this->rules)->not->toBeInstanceOf(FieldDayRuleSet::class)
        ->and($this->rules)->toBeInstanceOf(RuleSet::class);
});

// ---------------------------------------------------------------------------
// QSO points: Phone 1, CW 2, Digital 2
// ---------------------------------------------------------------------------

test('pointsForContact reads modes.points_wfd, not points_fd', function () {
    $mode = Mode::factory()->create(['points_wfd' => 2, 'points_fd' => 99]);
    $station = Station::factory()->make(['is_gota' => false]);

    expect($this->rules->pointsForContact($mode, $station))->toBe(2);
});

test('pointsForContact: Phone is 1 point, CW and Digital are 2', function () {
    $station = Station::factory()->make(['is_gota' => false]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone', 'points_wfd' => 1]);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW', 'points_wfd' => 2]);
    $digital = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital', 'points_wfd' => 2]);

    expect($this->rules->pointsForContact($phone, $station))->toBe(1)
        ->and($this->rules->pointsForContact($cw, $station))->toBe(2)
        ->and($this->rules->pointsForContact($digital, $station))->toBe(2);
});

test('pointsForContact honours a mode_rule_points override for WFD 2026', function () {
    $mode = Mode::factory()->create(['points_wfd' => 2]);
    $station = Station::factory()->make(['is_gota' => false]);

    ModeRulePoint::create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 5,
    ]);

    expect($this->rules->pointsForContact($mode, $station))->toBe(5);
});

test('pointsForContact ignores the GOTA flag — WFD has no GOTA station', function () {
    $mode = Mode::factory()->create(['points_wfd' => 2]);
    $gotaStation = Station::factory()->make(['is_gota' => true]);

    expect($this->rules->pointsForContact($mode, $gotaStation))->toBe(2);
});

// ---------------------------------------------------------------------------
// Satellite QSOs earn no QSO credit
// ---------------------------------------------------------------------------

test('qsoPoints excludes satellite QSOs and duplicates', function () {
    $config = wfdConfig($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 2]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => true,
        'is_satellite' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_satellite' => true,
    ]);

    expect($this->rules->qsoPoints($config))->toBe(6);
});

// ---------------------------------------------------------------------------
// Score = QSO points x (OM + 1)
// ---------------------------------------------------------------------------

test('score is QSO points x 1 when no objectives are achieved', function () {
    $config = wfdConfig($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 2]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    $breakdown = $this->rules->score($config);

    expect($breakdown->value('qso_points'))->toBe(10)
        ->and($breakdown->value('objective_multiplier'))->toBe(0)
        ->and($breakdown->value('total_multiplier'))->toBe(1)
        ->and($breakdown->total)->toBe(10);
});

test('objective multipliers accumulate and drive the score', function () {
    $config = wfdConfig($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 2]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    // Away from home (x3) + six bands (x6) = OM 9, so multiplier 10.
    achieveObjective($config, wfdObjective($this->wfd, 'away_from_home', 3));
    achieveObjective($config, wfdObjective($this->wfd, 'six_bands', 6));

    $breakdown = $this->rules->score($config);

    expect($breakdown->value('objective_multiplier'))->toBe(9)
        ->and($breakdown->value('total_multiplier'))->toBe(10)
        ->and($breakdown->total)->toBe(100)
        ->and($breakdown->formula)->toBe('QSO Points x (OM + 1)');
});

test('unverified objective claims do not count toward the multiplier', function () {
    $config = wfdConfig($this->wfd);
    $objective = wfdObjective($this->wfd, 'winlink_email', 1);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $objective->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => false,
    ]);

    expect($this->rules->objectiveMultiplier($config))->toBe(0);
});

test('objective progress counts achieved against available', function () {
    $config = wfdConfig($this->wfd);

    wfdObjective($this->wfd, 'qrp', 4);
    $achieved = wfdObjective($this->wfd, 'multiple_modes', 2);
    wfdObjective($this->wfd, 'special_bulletin', 1);

    achieveObjective($config, $achieved);

    expect($this->rules->objectiveProgress($config))
        ->toBe(['achieved' => 1, 'available' => 3]);
});

// ---------------------------------------------------------------------------
// Published objective multipliers, straight from the SOP
// ---------------------------------------------------------------------------

test('seeded objectives match the published WFDA 2026 multipliers', function () {
    // BonusTypeSeeder resolves both event types by code; WFD already exists
    // from beforeEach, so only FD needs creating.
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $this->seed(BonusTypeSeeder::class);

    $expected = [
        'alternative_power' => 1,
        'away_from_home' => 3,
        'multiple_antennas' => 1,
        'fm_satellite' => 2,
        'ssb_cw_satellite' => 3,
        'winlink_email' => 1,
        'special_bulletin' => 1,
        'six_bands' => 6,
        'twelve_bands' => 6,
        'multiple_modes' => 2,
        'qrp' => 4,
        'six_continuous_hours' => 2,
    ];

    $actual = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->whereNotNull('objective_multiplier')
        ->pluck('objective_multiplier', 'code')
        ->map(fn ($m) => (int) $m)
        ->all();

    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected)
        // The SOP's full slate is 12 objectives totalling OM 32, so a station
        // completing every one scores 33x its QSO points.
        ->and(array_sum($expected))->toBe(32);
});

test('every objective exposes its verbatim SOP text', function () {
    $codes = [
        'alternative_power', 'away_from_home', 'multiple_antennas', 'fm_satellite',
        'ssb_cw_satellite', 'winlink_email', 'special_bulletin', 'six_bands',
        'twelve_bands', 'multiple_modes', 'qrp', 'six_continuous_hours',
    ];

    foreach ($codes as $code) {
        $reference = $this->rules->bonusRuleReference($code);

        expect($reference)->not->toBeNull("missing rule reference for {$code}")
            ->and($reference['section'])->toBe('Objective & Multipliers')
            ->and($reference['text'])->toContain('OM x');
    }

    expect($this->rules->bonusRuleReference('emergency_power'))->toBeNull();
});

test('nomenclature uses WFD objective wording', function () {
    $terms = $this->rules->nomenclature();

    expect($terms->awardSingular)->toBe('Objective')
        ->and($terms->awardPlural)->toBe('Objectives')
        ->and($terms->awardValueLabel)->toBe('Multiplier')
        ->and($terms->rulebookName)->toBe('WFDA')
        ->and($terms->bulletinName)->toBe('WFD Special Bulletin')
        ->and($terms->bulletinSources)->toBe([]);
});

test('a deactivated objective stops counting toward the multiplier and the total', function () {
    $config = wfdConfig($this->wfd);

    $active = wfdObjective($this->wfd, 'qrp', 4);
    $retired = wfdObjective($this->wfd, 'special_bulletin', 1);

    achieveObjective($config, $active);
    achieveObjective($config, $retired);

    expect($this->rules->objectiveMultiplier($config))->toBe(5)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 2, 'available' => 2]);

    $retired->update(['is_active' => false]);

    // Achieved and available must move together, or the completion percentage lies.
    expect($this->rules->objectiveMultiplier($config))->toBe(4)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 1, 'available' => 1]);
});
