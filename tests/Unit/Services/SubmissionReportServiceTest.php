<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
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
