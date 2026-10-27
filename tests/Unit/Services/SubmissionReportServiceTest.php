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
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\W1awBulletin;
use App\Services\SubmissionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSubmissionConfig(array $configOverrides = [], array $eventOverrides = []): EventConfiguration
{
    $section = Section::factory()->create(['code' => 'CT']);

    $eventType = EventType::factory()->create(['code' => 'FD', 'name' => 'Field Day', 'is_active' => true]);

    $opClass = OperatingClass::create([
        'code' => 'A',
        'event_type_id' => $eventType->id,
        'name' => 'Class A',
        'description' => 'Portable emergency power',
        'allows_gota' => false,
        'allows_free_vhf' => false,
        'requires_emergency_power' => false,
    ]);

    $event = Event::factory()->create(array_merge([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ], $eventOverrides));

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'ARRL HQ',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'transmitter_count' => 3,
        'max_power_watts' => 100,
    ], $configOverrides));
}

test('getData returns station identification fields', function () {
    $config = makeSubmissionConfig();

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['callsign'])->toBe('W1AW');
    expect($data['club_name'])->toBe('ARRL HQ');
    expect($data['section'])->toBe('CT');
    expect($data['entry_class'])->toBe('3A');
});

test('getData returns power source flags', function () {
    $config = makeSubmissionConfig([
        'uses_commercial_power' => false,
        'uses_generator' => true,
        'uses_battery' => true,
        'uses_solar' => false,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['uses_commercial_power'])->toBeFalse();
    expect($data['uses_generator'])->toBeTrue();
    expect($data['uses_battery'])->toBeTrue();
    expect($data['uses_solar'])->toBeFalse();
});

test('getData returns score fields', function () {
    $config = makeSubmissionConfig();

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data)->toHaveKeys([
        'qso_base_points', 'power_multiplier', 'qso_score',
        'bonus_score', 'gota_bonus', 'final_score',
    ]);
    expect($data['qso_base_points'])->toBeInt();
    expect($data['final_score'])->toBeInt();
});

test('getData returns band_mode_grid with qsos and power per cell', function () {
    $config = makeSubmissionConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'power_watts' => 100,
        'points' => 2,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['band_mode_grid'][$mode->id][$band->id]['qsos'])->toBe(5);
    expect($data['band_mode_grid'][$mode->id][$band->id]['power'])->toBe(100);
});

test('operator list returns sorted callsigns', function () {
    $config = makeSubmissionConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);

    $user1 = User::factory()->create(['call_sign' => 'W1AW']);
    $user2 = User::factory()->create(['call_sign' => 'K1ABC']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $user1->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $user2->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['operators'])->toBe(['K1ABC', 'W1AW']);
});

test('total_pages is 2 without a GOTA station', function () {
    $config = makeSubmissionConfig(['has_gota_station' => false]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['total_pages'])->toBe(2);
});

test('total_pages is 3 with a GOTA station', function () {
    $config = makeSubmissionConfig(['has_gota_station' => true]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['total_pages'])->toBe(3);
});

test('includes the saved W1AW bulletin copy on its own page', function () {
    $config = makeSubmissionConfig(['has_gota_station' => false]);
    W1awBulletin::factory()->create([
        'event_configuration_id' => $config->id,
        'bulletin_text' => 'QST DE W1AW FIELD DAY MESSAGE',
        'frequency' => '7.0475',
        'mode' => 'cw',
    ]);

    $data = app(SubmissionReportService::class)->getData($config->fresh());

    expect($data['bulletin']['name'])->toBe('W1AW Bulletin')
        ->and($data['bulletin']['text'])->toBe('QST DE W1AW FIELD DAY MESSAGE')
        ->and($data['bulletin']['frequency'])->toBe('7.0475')
        ->and($data['total_pages'])->toBe(3);
});

test('bulletin is null without a saved copy', function () {
    $data = app(SubmissionReportService::class)->getData(makeSubmissionConfig());

    expect($data['bulletin'])->toBeNull();
});

test('participant count includes unique loggers and GOTA operators', function () {
    $config = makeSubmissionConfig(['has_gota_station' => true]);
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);

    $logger = User::factory()->create(['call_sign' => 'W1LOG']);
    $gotaOp = User::factory()->create(['call_sign' => 'KD7GOTA']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $logger->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'points' => 1,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $logger->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => true,
        'gota_operator_user_id' => $gotaOp->id,
        'points' => 1,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['participant_count'])->toBe(2);
});

test('bonus_checklist ignores EventBonus rows from other rules_versions', function () {
    // Pin event to 'TEST' (a registered ruleset). A stale row for the same code
    // scoped to '2025' must not leak into the checklist for a TEST event.
    $config = makeSubmissionConfig([], ['rules_version' => 'TEST']);

    $eventTypeId = $config->event->event_type_id;

    $currentType = BonusType::factory()->create([
        'event_type_id' => $eventTypeId,
        'code' => 'public_location',
        'rules_version' => 'TEST',
        'base_points' => 100,
        'is_active' => true,
    ]);

    $staleType = BonusType::factory()->create([
        'event_type_id' => $eventTypeId,
        'code' => 'public_location',
        'rules_version' => '2025',
        'base_points' => 500,
        'is_active' => true,
    ]);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $currentType->id,
        'quantity' => 1,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $staleType->id,
        'quantity' => 1,
        'calculated_points' => 500,
        'is_verified' => true,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    $publicLocation = collect($data['bonus_checklist'])->firstWhere('code', 'public_location');

    expect($publicLocation)->not->toBeNull()
        ->and($publicLocation['claimed'])->toBeTrue()
        ->and($publicLocation['points'])->toBe(100);
});

test('bonus_checklist appends ruleset-specific bonuses not in the ARRL form order', function () {
    $config = makeSubmissionConfig([], ['rules_version' => 'TEST']);

    $customType = BonusType::factory()->create([
        'event_type_id' => $config->event->event_type_id,
        'code' => 'use_fd_commander',
        'name' => 'Use Field Day Commander',
        'rules_version' => 'TEST',
        'base_points' => 100,
        'is_active' => true,
    ]);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $customType->id,
        'quantity' => 1,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    $data = app(SubmissionReportService::class)->getData($config);

    $custom = collect($data['bonus_checklist'])->firstWhere('code', 'use_fd_commander');

    expect($custom)->not->toBeNull()
        ->and($custom['name'])->toBe('Use Field Day Commander')
        ->and($custom['claimed'])->toBeTrue()
        ->and($custom['points'])->toBe(100)
        ->and($custom['is_verified'])->toBeTrue();
});

test('GOTA QSOs count in QSO points and get their own breakdown row', function () {
    $config = makeSubmissionConfig(['has_gota_station' => true]);
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1]);
    $attributes = [
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $phone->id,
        'is_duplicate' => false,
        'points' => 1,
    ];

    Contact::factory()->count(4)->create($attributes + ['is_gota_contact' => false, 'power_watts' => 100]);
    Contact::factory()->count(3)->create($attributes + ['is_gota_contact' => true, 'power_watts' => 20]);

    $data = app(SubmissionReportService::class)->getData($config);

    expect($data['qso_base_points'])->toBe(7)
        ->and($data['band_mode_grid'][$phone->id][$band->id]['qsos'])->toBe(4)
        ->and($data['gota_mode_row']['Phone'])->toBe(['qsos' => 3, 'power' => 20])
        ->and($data['gota_mode_row']['CW'])->toBe(['qsos' => 0, 'power' => 0])
        ->and($data['gota_bonus'])->toBe(15);

    $html = view('reports.submission-sheet-pdf', $data)->render();

    expect($html)->toContain('>GOTA</td>')
        ->not->toContain('do not list GOTA QSOs');
});

test('bonus_checklist leaves off unclaimed bonuses the entry class cannot earn', function () {
    $config = makeSubmissionConfig();

    BonusType::factory()->create([
        'event_type_id' => $config->event->event_type_id,
        'rules_version' => $config->event->resolved_rules_version,
        'code' => 'site_responsibilities',
        'eligible_classes' => ['B', 'C', 'D', 'E', 'F'],
    ]);

    BonusType::factory()->create([
        'event_type_id' => $config->event->event_type_id,
        'rules_version' => $config->event->resolved_rules_version,
        'code' => 'public_location',
        'eligible_classes' => ['A', 'B', 'F'],
    ]);

    $codes = collect(app(SubmissionReportService::class)->getData($config)['bonus_checklist'])->pluck('code');

    expect($codes)->not->toContain('site_responsibilities')
        ->not->toContain('_gota')
        ->toContain('public_location');
});

test('participant count includes volunteers who worked a shift but did not log', function () {
    $config = makeSubmissionConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $logger = User::factory()->create(['call_sign' => 'W1LOG']);
    $volunteer = User::factory()->create(['call_sign' => 'W1VOL']);
    $noShow = User::factory()->create(['call_sign' => 'W1NOS']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $logger->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
    ]);

    $shift = Shift::factory()->create(['event_configuration_id' => $config->id]);

    foreach ([
        [$logger, ShiftAssignment::STATUS_CHECKED_OUT],
        [$volunteer, ShiftAssignment::STATUS_CHECKED_IN],
        [$noShow, ShiftAssignment::STATUS_NO_SHOW],
    ] as [$user, $status]) {
        ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => $status]);
    }

    expect(app(SubmissionReportService::class)->getData($config)['participant_count'])->toBe(2);
});
