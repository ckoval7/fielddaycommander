<?php

use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create log-contacts permission
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);
    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);
});

test('requires authentication', function () {
    $this->get(route('logging.station-select'))
        ->assertRedirect();
});

test('requires log-contacts permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(StationSelect::class)
        ->assertForbidden();
});

test('renders successfully with permission', function () {
    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->assertStatus(200);
});

test('shows no active event message when no event is active', function () {
    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->assertSee('No Active Event');
});

test('shows stations for active event', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Phone Station 1',
    ]);

    Livewire::test(StationSelect::class)
        ->assertSee('Phone Station 1')
        ->assertSee('Available');
});

test('shows station as occupied when active session exists', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Busy Station',
    ]);

    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    Livewire::test(StationSelect::class)
        ->assertSee('Busy Station')
        ->assertSee('Occupied');
});

test('opens setup modal when selecting available station', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    Livewire::test(StationSelect::class)
        ->call('selectStation', $station->id)
        ->assertSet('showSetupModal', true)
        ->assertSet('selectedStationId', $station->id);
});

test('starting session creates operating session and redirects', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertRedirect();

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
});

test('validates required fields when starting session', function () {
    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->call('startSession')
        ->assertHasErrors(['selectedStationId', 'selectedBandId', 'selectedModeId']);
});

test('redirects to active session on mount if user has one', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    Livewire::test(StationSelect::class)
        ->assertRedirect(route('logging.session', $session));
});

test('takeover ends previous session', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    $otherUser = User::factory()->create();
    $oldSession = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now()->subHours(2),
        'end_time' => null,
    ]);

    Livewire::test(StationSelect::class)
        ->set('takeoverStationId', $station->id)
        ->call('confirmTakeover')
        ->assertSet('showSetupModal', true)
        ->assertSet('selectedStationId', $station->id);

    $oldSession->refresh();
    expect($oldSession->end_time)->not->toBeNull();
});

test('cancel setup resets state', function () {
    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', 1)
        ->set('selectedBandId', 1)
        ->set('showSetupModal', true)
        ->call('cancelSetup')
        ->assertSet('showSetupModal', false)
        ->assertSet('selectedStationId', null)
        ->assertSet('selectedBandId', null);
});

test('no stations message when event has no stations', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(StationSelect::class)
        ->assertSee('No Stations Configured');
});
