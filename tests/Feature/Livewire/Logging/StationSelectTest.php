<?php

use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use App\Services\EventContextService;
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

test('station select does not show stations during grace period', function () {
    $this->actingAs($this->user);

    Setting::set('post_event_grace_period_days', 30);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(5),
        'end_time' => now()->subDays(4),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::withoutEvents(fn () => Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Grace Period Station',
    ]));

    session(['viewing_event_id' => $event->id]);

    $service = app(EventContextService::class);
    expect($service->getGracePeriodStatus($event))->toBe('grace');

    Livewire::test(StationSelect::class)
        ->assertDontSee('Grace Period Station');
});

test('station select blocks logging for archived events', function () {
    $this->actingAs($this->user);

    Setting::set('post_event_grace_period_days', 7);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    session(['viewing_event_id' => $event->id]);

    $service = app(EventContextService::class);
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

test('cancel setup resets supervised session toggle', function () {
    $this->actingAs($this->user);

    Livewire::test(StationSelect::class)
        ->set('isSupervisedSession', true)
        ->set('showSetupModal', true)
        ->call('cancelSetup')
        ->assertSet('isSupervisedSession', false);
});

test('supervised toggle shown for GOTA station in setup modal', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $config->id,
    ]);

    Livewire::test(StationSelect::class)
        ->call('selectStation', $gotaStation->id)
        ->assertSee('GOTA Station Options')
        ->assertSee('Supervised Session');
});

test('supervised toggle not shown for non-GOTA station', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota' => false,
    ]);

    Livewire::test(StationSelect::class)
        ->call('selectStation', $station->id)
        ->assertDontSee('GOTA Station Options');
});

test('starting session on GOTA station with supervised toggle sets is_supervised', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $config->id,
    ]);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $gotaStation->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->set('isSupervisedSession', true)
        ->call('startSession')
        ->assertRedirect();

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $gotaStation->id,
        'is_supervised' => true,
    ]);
});

test('starting session on non-GOTA station ignores supervised toggle', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota' => false,
    ]);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->set('isSupervisedSession', true)
        ->call('startSession')
        ->assertRedirect();

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $station->id,
        'is_supervised' => false,
    ]);
});

test('prevents starting session with band/mode already active on another station', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station1 = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Station Alpha',
    ]);
    $station2 = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Station Bravo',
    ]);

    // Station Alpha already has an active session on 20m Phone
    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $station1->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    // Try to start same band/mode on Station Bravo
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station2->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasErrors('selectedBandId');

    // No new session should be created for station2
    expect(OperatingSession::where('station_id', $station2->id)->count())->toBe(0);
});

test('GOTA station is exempt from band/mode exclusivity rule', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station1 = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Phone Station',
        'is_gota' => false,
    ]);
    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'name' => 'GOTA Station',
    ]);

    // Station 1 already active on 20m Phone
    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $station1->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    // GOTA station on same band/mode should be allowed (FD Rule 6.9 exception)
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $gotaStation->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors()
        ->assertRedirect();
});

test('GOTA session does not block non-GOTA station from same band/mode', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'name' => 'GOTA Station',
    ]);
    $station2 = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Phone Station',
        'is_gota' => false,
    ]);

    // GOTA already active on 20m Phone
    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    // Non-GOTA station on same band/mode should be allowed (GOTA sessions don't count)
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station2->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors()
        ->assertRedirect();
});

test('allows same band/mode after previous session ended', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station1 = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);
    $station2 = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    // Station Alpha had a session on 20m Phone but it ended
    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $station1->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now()->subHour(),
        'end_time' => now()->subMinutes(10),
    ]);

    // Should be allowed since the other session ended
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station2->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors()
        ->assertRedirect();
});

test('allows different band/mode on another station', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $cwMode = Mode::create([
        'name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2,
    ]);

    $station1 = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);
    $station2 = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    // Station 1 active on 20m Phone
    $otherUser = User::factory()->create();
    OperatingSession::factory()->create([
        'station_id' => $station1->id,
        'operator_user_id' => $otherUser->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    // Station 2 on 20m CW — different mode, should be allowed
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station2->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $cwMode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors()
        ->assertRedirect();
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

test('stationSupportedBands returns intersecting bands when radio and antenna share bands', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $band40m = Band::factory()->create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach([$this->band->id, $band40m->id]); // radio supports 20m and 40m

    $antenna = Equipment::factory()->create(['type' => 'antenna']);
    $antenna->bands()->attach([$this->band->id]); // antenna supports 20m only

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    $station->additionalEquipment()->attach($antenna->id, ['event_id' => $event->id]);

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->not->toBeNull()
        ->and($result->pluck('id')->toArray())->toEqual([$this->band->id]); // only 20m
});

test('stationSupportedBands returns null when station has no radio', function () {
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

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->toBeNull();
});

test('stationSupportedBands returns null when station has no antennas assigned', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band->id);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    // No additionalEquipment attached

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->toBeNull();
});

test('stationSupportedBands returns empty collection when radio and antenna share no bands', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $band40m = Band::factory()->create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band->id); // radio: 20m

    $antenna = Equipment::factory()->create(['type' => 'antenna']);
    $antenna->bands()->attach($band40m->id); // antenna: 40m only — no overlap

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    $station->additionalEquipment()->attach($antenna->id, ['event_id' => $event->id]);

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->not->toBeNull()
        ->and($result)->toHaveCount(0);
});

test('band warning shown when selected band is not covered by any station antenna', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $band40m = Band::factory()->create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    // Radio supports 20m (the band we will select)
    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band->id);

    // Antenna only covers 40m — no 20m coverage
    $antenna = Equipment::factory()->create(['type' => 'antenna']);
    $antenna->bands()->attach($band40m->id);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    $station->additionalEquipment()->attach($antenna->id, ['event_id' => $event->id]);

    $component = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $this->band->id);

    $warning = $component->get('bandWarning');
    expect($warning)->not->toBeNull()
        ->and($warning['type'])->toBe('warning')
        ->and($warning['message'])->toContain('20m')
        ->and($warning['message'])->toContain('antenna');
});

test('does not redirect to external session on mount', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    // User has an active external session
    OperatingSession::factory()->external('N1MM')->create([
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    // Should NOT redirect — external sessions don't count for redirect
    Livewire::test(StationSelect::class)
        ->assertStatus(200)
        ->assertNoRedirect();
});

test('shows external session as occupied with source indicator', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'External Station',
    ]);

    $operator = User::factory()->create(['call_sign' => 'K3CPK']);
    OperatingSession::factory()->external('N1MM')->create([
        'station_id' => $station->id,
        'operator_user_id' => $operator->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(),
        'end_time' => null,
    ]);

    Livewire::test(StationSelect::class)
        ->assertSee('External Station')
        ->assertSee('Occupied')
        ->assertSee('K3CPK')
        ->assertSee('N1MM');
});

test('idle external session can be taken over', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    $operator = User::factory()->create();
    OperatingSession::factory()->external('N1MM')->create([
        'station_id' => $station->id,
        'operator_user_id' => $operator->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now()->subHours(2), // Idle by time (>30 min)
        'end_time' => null,
    ]);

    // Idle external sessions can be taken over just like regular idle sessions
    Livewire::test(StationSelect::class)
        ->assertSee('Idle')
        ->call('selectStation', $station->id)
        ->assertSet('showTakeoverModal', true)
        ->assertSet('takeoverStationId', $station->id);
});

test('active external session is occupied and not selectable', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    $operator = User::factory()->create();
    OperatingSession::factory()->external('N1MM')->create([
        'station_id' => $station->id,
        'operator_user_id' => $operator->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'start_time' => now(), // Recent - not idle
        'end_time' => null,
    ]);

    // Active external sessions are occupied and not selectable
    Livewire::test(StationSelect::class)
        ->assertSee('Occupied')
        ->call('selectStation', $station->id)
        ->assertSet('showSetupModal', false)
        ->assertSet('showTakeoverModal', false);
});

test('shows stations 15 minutes before event starts for session setup', function () {
    $this->actingAs($this->user);

    // Event starts in 10 minutes (within 15-minute setup window)
    $event = Event::factory()->create([
        'start_time' => now()->addMinutes(10),
        'end_time' => now()->addHours(24)->addMinutes(10),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'Setup Station',
    ]);

    Livewire::test(StationSelect::class)
        ->assertSee('Setup Station')
        ->assertSee('Available');
});

test('does not show stations more than 15 minutes before event starts', function () {
    $this->actingAs($this->user);

    // Event starts in 20 minutes (outside 15-minute setup window)
    $event = Event::factory()->create([
        'start_time' => now()->addMinutes(20),
        'end_time' => now()->addHours(24)->addMinutes(20),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(StationSelect::class)
        ->assertSee('No Active Event');
});

test('can start session during setup window before event starts', function () {
    $this->actingAs($this->user);

    // Event starts in 5 minutes
    $event = Event::factory()->create([
        'start_time' => now()->addMinutes(5),
        'end_time' => now()->addHours(24)->addMinutes(5),
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
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
    ]);
});
