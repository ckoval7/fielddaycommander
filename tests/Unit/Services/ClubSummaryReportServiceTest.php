<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use App\Services\ClubSummaryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeClubSummaryConfig(array $configOverrides = [], array $eventOverrides = []): EventConfiguration
{
    $section = Section::factory()->create(['code' => 'OR']);

    $eventType = EventType::factory()->create(['code' => 'FD', 'name' => 'Field Day', 'is_active' => true]);

    $opClass = OperatingClass::create([
        'code' => '3A',
        'event_type_id' => $eventType->id,
        'name' => 'Class 3A',
        'description' => 'Three transmitters',
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
        'callsign' => 'W7ARC',
        'club_name' => 'Oregon ARC',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'max_power_watts' => 100,
    ], $configOverrides));
}

test('getData returns correct event identification fields', function () {
    $config = makeClubSummaryConfig();

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data['callsign'])->toBe('W7ARC');
    expect($data['club_name'])->toBe('Oregon ARC');
    expect($data['operating_class'])->toBe('3A');
    expect($data['section'])->toBe('OR');
});

test('getData returns score keys', function () {
    $config = makeClubSummaryConfig();

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data)->toHaveKeys(['qso_base_points', 'power_multiplier', 'qso_score', 'bonus_score', 'final_score']);
    expect($data['qso_base_points'])->toBeInt();
    expect($data['power_multiplier'])->toBeIn(['1', '2', '5', 1, 2, 5]);
    expect($data['qso_score'])->toBeInt();
    expect($data['bonus_score'])->toBeInt();
    expect($data['final_score'])->toBeInt();
});

test('getData returns band_mode_grid and bands arrays', function () {
    $config = makeClubSummaryConfig();

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data)->toHaveKeys(['band_mode_grid', 'bands']);
    expect($data['band_mode_grid'])->toBeArray();
    expect($data['bands'])->toBeArray();
});

test('getData returns bonuses including verified and claimed', function () {
    $config = makeClubSummaryConfig();

    $eventType = $config->operatingClass->eventType ?? \App\Models\EventType::where('code', 'FD')->first();

    $bonusType = \App\Models\BonusType::factory()->create([
        'event_type_id' => $eventType->id,
        'name' => 'Public Location',
        'base_points' => 100,
    ]);

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'calculated_points' => 50,
        'is_verified' => false,
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data['bonuses'])->toBeArray();
    expect($data['bonuses'])->toHaveCount(2);

    $verified = collect($data['bonuses'])->where('is_verified', true)->first();
    $claimed = collect($data['bonuses'])->where('is_verified', false)->first();

    expect($verified)->not->toBeNull();
    expect($claimed)->not->toBeNull();
    expect($verified['points'])->toBe(100);
    expect($claimed['points'])->toBe(50);
});

test('getData returns operators with valid qso counts', function () {
    $config = makeClubSummaryConfig();

    $band = Band::factory()->create(['name' => '40m', 'frequency_mhz' => 7.2, 'allowed_fd' => true, 'sort_order' => 1]);
    $mode = Mode::factory()->create(['name' => 'SSB', 'category' => 'Phone']);
    $user = User::factory()->create(['call_sign' => 'KD7TEST']);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    // A duplicate that should NOT be counted
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data['operators'])->toBeArray();

    $operator = collect($data['operators'])->firstWhere('call_sign', 'KD7TEST');

    expect($operator)->not->toBeNull();
    expect($operator['valid_qsos'])->toBe(3);
    expect($operator['call_sign'])->toBe('KD7TEST');
});

test('getData returns GOTA data fields', function () {
    $config = makeClubSummaryConfig([
        'has_gota_station' => true,
        'gota_callsign' => 'W7ARC/GOTA',
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data)->toHaveKeys([
        'has_gota_station',
        'gota_callsign',
        'gota_contact_count',
        'gota_bonus',
        'gota_coach_bonus',
        'gota_operators',
    ]);
    expect($data['has_gota_station'])->toBeTrue();
    expect($data['gota_callsign'])->toBe('W7ARC/GOTA');
    expect($data['gota_operators'])->toBeArray();
});

test('qso_base_points excludes GOTA contacts', function () {
    $config = makeClubSummaryConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    // Regular contacts worth 2 points each
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'points' => 2,
    ]);

    // GOTA contact worth 2 points — should NOT count
    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 2,
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data['qso_base_points'])->toBe(4);
});

test('band_mode_grid excludes GOTA contacts', function () {
    $config = makeClubSummaryConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'points' => 2,
    ]);

    // GOTA contact should not appear in grid
    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 2,
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    $cwRow = collect($data['band_mode_grid'])->firstWhere('mode', 'CW');
    expect($cwRow)->not->toBeNull();
    expect($cwRow['total'])->toBe(3);
    expect($cwRow['cells'][$band->id])->toBe(3);
});

test('gota_operators includes registered and unregistered operators', function () {
    $config = makeClubSummaryConfig(['has_gota_station' => true]);
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.2, 'allowed_fd' => true, 'sort_order' => 2]);
    $mode = Mode::factory()->create(['name' => 'SSB', 'category' => 'Phone']);
    $registeredUser = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'call_sign' => 'KD7JD',
    ]);

    // 2 contacts by registered GOTA operator
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => true,
        'gota_operator_user_id' => $registeredUser->id,
        'gota_operator_first_name' => null,
        'gota_operator_last_name' => null,
        'gota_operator_callsign' => null,
        'points' => 1,
    ]);

    // 1 contact by unregistered GOTA operator
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => true,
        'gota_operator_user_id' => null,
        'gota_operator_first_name' => 'Bob',
        'gota_operator_last_name' => 'Smith',
        'gota_operator_callsign' => null,
        'points' => 1,
    ]);

    $data = app(ClubSummaryReportService::class)->getData($config);

    expect($data['gota_operators'])->toHaveCount(2);

    $jane = collect($data['gota_operators'])->firstWhere('name', 'Jane Doe');
    expect($jane)->not->toBeNull();
    expect($jane['callsign'])->toBe('KD7JD');
    expect($jane['contacts'])->toBe(2);

    $bob = collect($data['gota_operators'])->firstWhere('name', 'Bob Smith');
    expect($bob)->not->toBeNull();
    expect($bob['contacts'])->toBe(1);
});
