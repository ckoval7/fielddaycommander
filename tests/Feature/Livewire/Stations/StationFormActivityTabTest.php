<?php

use App\Livewire\Stations\StationForm;
use App\Models\Band;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-stations']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('manage-stations');
    $this->actingAs($this->user);

    $eventType = EventType::create([
        'name' => 'Field Day',
        'code' => 'FD',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);

    $section = Section::create([
        'code' => 'AK',
        'name' => 'Alaska',
        'region' => 'KL7',
        'country' => 'US',
        'is_active' => true,
    ]);

    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '2A',
        'name' => 'Class 2A',
        'allows_gota' => true,
        'max_power_watts' => 150,
        'requires_emergency_power' => false,
    ]);

    $this->event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
    ]);

    $this->radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'power_output_watts' => 100,
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Test Station',
    ]);
});

test('sessions computed property returns sessions for the station ordered newest first', function () {
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $op = User::factory()->create(['call_sign' => 'W1ALI']);

    $older = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $op->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subHours(2),
        'qso_count' => 5,
    ]);

    $newer = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $op->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'start_time' => now()->subHour(),
        'end_time' => null,
        'qso_count' => 7,
    ]);

    $component = Livewire::test(StationForm::class, ['station' => $this->station]);

    $sessions = $component->instance()->sessions;

    expect($sessions)->toHaveCount(2)
        ->and($sessions->first()->id)->toBe($newer->id)
        ->and($sessions->last()->id)->toBe($older->id);
});

test('totalQsoCount sums qso_count across all sessions for the station', function () {
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $op = User::factory()->create();

    OperatingSession::factory()->count(3)->sequence(
        ['qso_count' => 4],
        ['qso_count' => 6],
        ['qso_count' => 10],
    )->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $op->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    $component = Livewire::test(StationForm::class, ['station' => $this->station]);

    expect($component->instance()->totalQsoCount)->toBe(20);
});

test('activity tab shows empty state when station has no sessions', function () {
    Livewire::test(StationForm::class, ['station' => $this->station])
        ->set('activeTab', 'activity')
        ->assertSee('No operating sessions logged for this station yet.');
});

test('activity tab renders one row per session with operator, band, mode, and qso count', function () {
    $band = Band::factory()->create(['name' => '20m']);
    $mode = Mode::factory()->create(['name' => 'SSB']);
    $alice = User::factory()->create(['call_sign' => 'W1ALI']);
    $bob = User::factory()->create(['call_sign' => 'W1BOB']);

    OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $alice->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
        'qso_count' => 12,
    ]);

    OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $bob->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'start_time' => now()->subMinutes(30),
        'end_time' => null,
        'qso_count' => 5,
    ]);

    Livewire::test(StationForm::class, ['station' => $this->station])
        ->set('activeTab', 'activity')
        ->assertSee('W1ALI')
        ->assertSee('W1BOB')
        ->assertSee('20m')
        ->assertSee('SSB')
        ->assertSee('12')
        ->assertSee('5')
        ->assertSee('Active');
});

test('activity tab shows total qso count across sessions', function () {
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $op = User::factory()->create();

    OperatingSession::factory()->count(2)->sequence(
        ['qso_count' => 8],
        ['qso_count' => 9],
    )->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $op->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    Livewire::test(StationForm::class, ['station' => $this->station])
        ->set('activeTab', 'activity')
        ->assertSee('Total QSOs')
        ->assertSee('17');
});
