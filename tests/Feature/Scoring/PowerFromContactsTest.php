<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logging\StationSelect;
use App\Livewire\Scoring;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\CabrilloExporter;
use App\Services\EventContextService;
use App\Services\SubmissionReportService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $this->seed(EventTypeSeeder::class);
    $this->seed(OperatingClassSeeder::class);

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $this->cw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);

    $this->event = Event::factory()->create([
        'event_type_id' => EventType::where('code', 'FD')->value('id'),
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => OperatingClass::where('code', 'A')->where('event_type_id', $this->event->event_type_id)->value('id'),
        'max_power_watts' => 100,
        'uses_commercial_power' => false,
        'uses_generator' => true,
    ]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'max_power_watts' => 100]);

    $this->user = User::factory()->create();
    Permission::findOrCreate('log-contacts', 'web');
    Permission::findOrCreate('view-scores', 'web');
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo(['log-contacts', 'view-scores']);
    $this->user->assignRole('Operator');

    $this->logContactAt = function (int $watts): Contact {
        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->cw->id,
            'power_watts' => $watts,
        ]);

        return Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $this->user->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->cw->id,
            'section_id' => $this->section->id,
            'power_watts' => $watts,
            'points' => 2,
        ]);
    };
});

test('the multiplier follows the highest power on any contact, not the event or station setting', function () {
    ($this->logContactAt)(100);
    ($this->logContactAt)(150);

    $config = $this->config->fresh();

    expect($config->effectiveMaxPowerWatts())->toBe(150)
        ->and($config->calculatePowerMultiplier())->toBe('1');
});

test('contacts at 100 W or less keep the 2x multiplier even when a station is set higher', function () {
    $this->station->update(['max_power_watts' => 1500]);
    ($this->logContactAt)(100);

    $config = $this->config->fresh();

    expect($config->effectiveMaxPowerWatts())->toBe(100)
        ->and($config->calculatePowerMultiplier())->toBe('2');
});

test('before any contact the planned event and station power stands in', function () {
    $this->station->update(['max_power_watts' => 150]);

    expect($this->config->fresh()->effectiveMaxPowerWatts())->toBe(150);
});

test('submission sheet, cabrillo and scoring page agree on a high-power contact', function () {
    ($this->logContactAt)(400);
    $config = $this->config->fresh();

    $sheet = app(SubmissionReportService::class)->getData($config);
    $cabrillo = app(CabrilloExporter::class)->export($config);

    expect((string) $sheet['power_multiplier'])->toBe('1')
        ->and($cabrillo)->toContain('CATEGORY-POWER: HIGH');

    app(EventContextService::class)->clearCache();
    $this->actingAs($this->user);

    Livewire::test(Scoring::class)
        ->assertSet('powerMultiplier', 1)
        ->assertSee('Your highest-power contact was made at 400W (over 100W), which gives a 1× multiplier.');
});

test('a contact above the class A 500 W limit is logged but scores 0 points', function () {
    $over = ($this->logContactAt)(1500);
    $within = ($this->logContactAt)(500);

    expect($over->fresh()->points)->toBe(0)
        ->and($within->fresh()->points)->toBe(2)
        ->and($this->config->fresh()->overPowerCapContactCount())->toBe(1);
});

test('the scoring page warns about contacts above the power limit', function () {
    ($this->logContactAt)(1500);

    app(EventContextService::class)->clearCache();
    $this->actingAs($this->user);

    Livewire::test(Scoring::class)
        ->assertSet('zeroPointContactCount', 0)
        ->assertSee('1 contact logged above the 500W limit for Class A. They score 0 points.');
});

test('classes without a power limit keep the points of a high-power contact', function () {
    $this->config->update([
        'operating_class_id' => OperatingClass::where('code', 'D')->where('event_type_id', $this->event->event_type_id)->value('id'),
    ]);

    expect(($this->logContactAt)(1500)->fresh()->points)->toBe(2);
});

test('the power limit comes from the rules version', function () {
    // Class A is limited to 500 W under ARRL Field Day; Class D has no limit.
    expect($this->config->fresh()->powerCapWatts())->toBe(500)
        ->and(($this->logContactAt)(400)->fresh()->points)->toBe(2);

    $this->config->update([
        'operating_class_id' => OperatingClass::where('code', 'D')->where('event_type_id', $this->event->event_type_id)->value('id'),
    ]);

    expect($this->config->fresh()->powerCapWatts())->toBeNull();
});

test('Winter Field Day contacts above 100 W score 0 points', function () {
    $wfd = EventType::where('code', 'WFD')->value('id');
    $event = Event::factory()->create([
        'event_type_id' => $wfd,
        'rules_version' => '2026',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => OperatingClass::where('code', 'O')->where('event_type_id', $wfd)->value('id'),
    ]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id]);

    expect($this->config->fresh()->powerCapWatts())->toBe(100)
        ->and(($this->logContactAt)(150)->fresh()->points)->toBe(0)
        ->and(($this->logContactAt)(100)->fresh()->points)->toBe(2);
});

test('a session cannot be started above the radio rated power', function () {
    $radio = Equipment::factory()->create(['type' => 'radio', 'power_output_watts' => 100]);
    $this->station->update(['radio_equipment_id' => $radio->id]);
    app(EventContextService::class)->clearCache();

    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $this->station->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->cw->id)
        ->set('powerWatts', 1500)
        ->call('startSession')
        ->assertHasErrors(['powerWatts' => 'max'])
        ->assertSee("This station's radio is rated for 100 W. Enter 100 W or less.");

    expect(OperatingSession::where('station_id', $this->station->id)->exists())->toBeFalse();
});

test('a session at the radio rated power starts', function () {
    $radio = Equipment::factory()->create(['type' => 'radio', 'power_output_watts' => 100]);
    $this->station->update(['radio_equipment_id' => $radio->id]);
    app(EventContextService::class)->clearCache();

    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $this->station->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->cw->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors();

    expect(OperatingSession::where('station_id', $this->station->id)->value('power_watts'))->toBe(100);
});
