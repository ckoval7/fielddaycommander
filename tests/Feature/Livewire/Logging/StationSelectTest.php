<?php

use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Equipment;
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

test('station select allows logging during grace period', function () {
    $this->actingAs($this->user);

    \App\Models\Setting::set('post_event_grace_period_days', 30);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(5),
        'end_time' => now()->subDays(4),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Grace Period Station',
    ]);

    session(['viewing_event_id' => $event->id]);

    $service = app(\App\Services\EventContextService::class);
    expect($service->getGracePeriodStatus($event))->toBe('grace');

    Livewire::test(StationSelect::class)
        ->assertSee('Grace Period Station')
        ->assertSee('Available');
});

test('station select blocks logging for archived events', function () {
    $this->actingAs($this->user);

    \App\Models\Setting::set('post_event_grace_period_days', 7);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    session(['viewing_event_id' => $event->id]);

    $service = app(\App\Services\EventContextService::class);
    expect($service->getGracePeriodStatus($event))->toBe('archived');

    Livewire::test(StationSelect::class)
        ->assertSee('No Active Event');
});

test('band warning is null when selected band matches station radio', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id)
        ->assertSet('bandWarning', null);
});

test('band warning shown when selected band is not supported by station radio', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $otherBand = Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    $radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);
    $radio->bands()->attach($otherBand);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);

    $component = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id);

    $warning = $component->get('bandWarning');
    expect($warning)->not->toBeNull()
        ->and($warning['type'])->toBe('warning')
        ->and($warning['message'])->toContain('20m')
        ->and($warning['message'])->toContain('Icom IC-7300');
});

test('band warning shows info when station has no radio assigned', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => null,
    ]);

    $component = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id);

    $warning = $component->get('bandWarning');
    expect($warning)->not->toBeNull()
        ->and($warning['type'])->toBe('info')
        ->and($warning['message'])->toContain('no radio assigned');
});

test('band warning shows info when station radio has no bands configured', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    // No bands attached to radio

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);

    $component = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id);

    $warning = $component->get('bandWarning');
    expect($warning)->not->toBeNull()
        ->and($warning['type'])->toBe('info')
        ->and($warning['message'])->toContain('no band information configured');
});

test('band warning is null when no band is selected', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => null,
    ]);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->assertSet('bandWarning', null);
});
