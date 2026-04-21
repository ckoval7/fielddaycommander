<?php

use App\Livewire\Stations\StationClone;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-stations', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('manage-stations');
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

test('component renders successfully for authorized user', function () {
    Livewire::test(StationClone::class)
        ->assertOk();
});

test('mount throws authorization exception for user without manage-stations permission', function () {
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized);

    Livewire::test(StationClone::class)
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Source events computed property
// ---------------------------------------------------------------------------

test('sourceEvents returns events that have stations regardless of date', function () {
    // Past event WITH stations (should appear)
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->create(['event_configuration_id' => $pastConfig->id]);

    // Past event WITHOUT stations (should NOT appear)
    $pastEventNoStations = Event::factory()->create([
        'start_time' => appNow()->subDays(20),
        'end_time' => appNow()->subDays(19),
        'is_active' => false,
    ]);
    EventConfiguration::factory()->create(['event_id' => $pastEventNoStations->id]);

    // Future event with stations (should appear)
    $futureEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $futureConfig = EventConfiguration::factory()->create(['event_id' => $futureEvent->id]);
    Station::factory()->create(['event_configuration_id' => $futureConfig->id]);

    $component = Livewire::test(StationClone::class);
    $sourceEvents = $component->get('sourceEvents');

    $ids = collect($sourceEvents)->pluck('id');
    expect($ids)->toContain($pastEvent->id)
        ->and($ids)->toContain($futureEvent->id)
        ->and($ids)->not->toContain($pastEventNoStations->id);
});

test('sourceEvents includes station count', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->count(3)->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class);
    $sourceEvents = collect($component->get('sourceEvents'));

    $entry = $sourceEvents->firstWhere('id', $pastEvent->id);
    expect($entry)->not->toBeNull()
        ->and($entry['station_count'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Target events computed property
// ---------------------------------------------------------------------------

test('targetEvents returns future and active events', function () {
    // Future event with config (should appear)
    $futureEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    EventConfiguration::factory()->create(['event_id' => $futureEvent->id]);

    // Active event with config (should appear)
    $activeEvent = Event::factory()->create([
        'start_time' => appNow()->subHours(2),
        'end_time' => appNow()->addHours(22),
        'is_active' => true,
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    // Past event (should NOT appear)
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class);
    $targetEvents = collect($component->get('targetEvents'));

    $eventConfigIds = $targetEvents->pluck('id');
    $futureConfig = EventConfiguration::where('event_id', $futureEvent->id)->first();
    $activeConfig = EventConfiguration::where('event_id', $activeEvent->id)->first();
    $pastConfig = EventConfiguration::where('event_id', $pastEvent->id)->first();

    expect($eventConfigIds)->toContain($futureConfig->id)
        ->and($eventConfigIds)->toContain($activeConfig->id)
        ->and($eventConfigIds)->not->toContain($pastConfig->id);
});

test('targetEvents excludes source event', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->create(['event_configuration_id' => $pastConfig->id]);

    $futureEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $futureConfig = EventConfiguration::factory()->create(['event_id' => $futureEvent->id]);

    $component = Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id);

    $targetEvents = collect($component->get('targetEvents'));
    // The source event config should not be in target events
    expect($targetEvents->pluck('id'))->not->toContain($pastConfig->id);
});

// ---------------------------------------------------------------------------
// Station loading when sourceEventId changes
// ---------------------------------------------------------------------------

test('updatedSourceEventId loads stations from source event', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->count(2)->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id);

    $availableStations = $component->get('availableStations');
    expect($availableStations)->toHaveCount(2);
});

test('updatedSourceEventId selects all stations by default', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    $stations = Station::factory()->count(2)->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id);

    $selectedIds = $component->get('selectedStationIds');
    expect($selectedIds)->toContain($stations[0]->id)
        ->and($selectedIds)->toContain($stations[1]->id);

    $component->assertSet('selectAll', true);
});

test('loadStationsFromEvent clears stations when sourceEventId is null', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->create(['event_configuration_id' => $pastConfig->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        ->set('sourceEventId', null)
        ->assertSet('selectAll', false)
        ->assertSet('selectedStationIds', []);
});

// ---------------------------------------------------------------------------
// Select all / deselect all toggle
// ---------------------------------------------------------------------------

test('toggleSelectAll selects all when selectAll is true', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    $stations = Station::factory()->count(3)->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        // Manually deselect all first, then set selectAll to true and call toggle
        ->set('selectedStationIds', [])
        ->set('selectAll', true)
        ->call('toggleSelectAll');

    $selectedIds = $component->get('selectedStationIds');
    expect($selectedIds)->toContain($stations[0]->id)
        ->and($selectedIds)->toContain($stations[1]->id)
        ->and($selectedIds)->toContain($stations[2]->id);
});

test('toggleSelectAll deselects all when selectAll is false', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->count(2)->create(['event_configuration_id' => $pastConfig->id]);

    $component = Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        ->set('selectAll', false)
        ->call('toggleSelectAll');

    $component->assertSet('selectedStationIds', []);
});

test('updatedSelectedStationIds updates selectAll when all stations are selected', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    $stations = Station::factory()->count(2)->create(['event_configuration_id' => $pastConfig->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        ->set('selectedStationIds', $stations->pluck('id')->toArray())
        ->assertSet('selectAll', true);
});

test('updatedSelectedStationIds sets selectAll false when not all are selected', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    $stations = Station::factory()->count(2)->create(['event_configuration_id' => $pastConfig->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        ->set('selectedStationIds', [$stations[0]->id])
        ->assertSet('selectAll', false);
});

// ---------------------------------------------------------------------------
// Modal open / close
// ---------------------------------------------------------------------------

test('openModal sets showModal to true and resets form', function () {
    Livewire::test(StationClone::class)
        ->dispatch('open-clone-modal')
        ->assertSet('showModal', true);
});

test('closeModal sets showModal to false', function () {
    Livewire::test(StationClone::class)
        ->dispatch('open-clone-modal')
        ->call('closeModal')
        ->assertSet('showModal', false);
});

// ---------------------------------------------------------------------------
// Form reset
// ---------------------------------------------------------------------------

test('resetForm clears sourceEventId, selectedStationIds, and selectAll', function () {
    $pastEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    Station::factory()->create(['event_configuration_id' => $pastConfig->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $pastEvent->id)
        ->set('selectAll', true)
        ->call('resetForm')
        ->assertSet('sourceEventId', null)
        ->assertSet('selectAll', false)
        ->assertSet('selectedStationIds', [])
        ->assertSet('nameSuffix', null)
        ->assertSet('conflictPreview', null)
        ->assertSet('showConflicts', false);
});

test('resetForm restores copyEquipmentAssignments to true', function () {
    Livewire::test(StationClone::class)
        ->set('copyEquipmentAssignments', false)
        ->call('resetForm')
        ->assertSet('copyEquipmentAssignments', true);
});

// ---------------------------------------------------------------------------
// Conflict preview cancel
// ---------------------------------------------------------------------------

test('cancelConflictPreview resets conflict state', function () {
    $conflict = [
        'equipment_id' => 1,
        'equipment_type' => 'radio',
        'make_model' => 'Yaesu FT-991A',
        'reason' => 'Already committed',
        'station_name' => 'Phone Station',
    ];

    Livewire::test(StationClone::class)
        ->set('showConflicts', true)
        ->set('conflictPreview', ['conflicts' => [$conflict]])
        ->call('cancelConflictPreview')
        ->assertSet('showConflicts', false)
        ->assertSet('conflictPreview', null);
});

// ---------------------------------------------------------------------------
// Clone execution – basic success case
// ---------------------------------------------------------------------------

test('proceedWithClone creates stations in target event', function () {
    // Source: past event with stations
    $sourceEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $sourceConfig = EventConfiguration::factory()->create(['event_id' => $sourceEvent->id]);
    $stations = Station::factory()->count(2)->create(['event_configuration_id' => $sourceConfig->id]);

    // Target: future event
    $targetEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $targetConfig = EventConfiguration::factory()->create(['event_id' => $targetEvent->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $sourceEvent->id)
        ->set('selectedStationIds', $stations->pluck('id')->toArray())
        ->set('targetEventId', $targetConfig->id)
        ->set('copyEquipmentAssignments', false)
        ->call('proceedWithClone')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertDispatched('stations-cloned')
        ->assertSet('showModal', false);

    expect(Station::where('event_configuration_id', $targetConfig->id)->count())->toBe(2);
});

test('proceedWithClone requires authorized user', function () {
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized);

    $sourceEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $sourceConfig = EventConfiguration::factory()->create(['event_id' => $sourceEvent->id]);
    $station = Station::factory()->create(['event_configuration_id' => $sourceConfig->id]);

    $targetEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $targetConfig = EventConfiguration::factory()->create(['event_id' => $targetEvent->id]);

    // mount() authorize() will fail for unauthorized user
    Livewire::test(StationClone::class)
        ->assertForbidden();
});

test('proceedWithClone validates required fields', function () {
    Livewire::test(StationClone::class)
        ->set('sourceEventId', null)
        ->set('selectedStationIds', [])
        ->set('targetEventId', null)
        ->call('proceedWithClone')
        ->assertHasErrors(['sourceEventId', 'selectedStationIds', 'targetEventId']);
});

test('proceedWithClone applies name suffix when set', function () {
    $sourceEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $sourceConfig = EventConfiguration::factory()->create(['event_id' => $sourceEvent->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $sourceConfig->id,
        'name' => 'Phone Station',
    ]);

    $targetEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $targetConfig = EventConfiguration::factory()->create(['event_id' => $targetEvent->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $sourceEvent->id)
        ->set('selectedStationIds', [$station->id])
        ->set('targetEventId', $targetConfig->id)
        ->set('copyEquipmentAssignments', false)
        ->set('nameSuffix', '2026')
        ->call('proceedWithClone')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stations', [
        'event_configuration_id' => $targetConfig->id,
        'name' => 'Phone Station 2026',
    ]);
});

test('checkForConflicts dispatches no conflicts and proceeds with clone when no conflicts exist', function () {
    $sourceEvent = Event::factory()->create([
        'start_time' => appNow()->subDays(10),
        'end_time' => appNow()->subDays(9),
        'is_active' => false,
    ]);
    $sourceConfig = EventConfiguration::factory()->create(['event_id' => $sourceEvent->id]);
    $station = Station::factory()->create(['event_configuration_id' => $sourceConfig->id]);

    $targetEvent = Event::factory()->create([
        'start_time' => appNow()->addDays(5),
        'end_time' => appNow()->addDays(6),
        'is_active' => false,
    ]);
    $targetConfig = EventConfiguration::factory()->create(['event_id' => $targetEvent->id]);

    Livewire::test(StationClone::class)
        ->set('sourceEventId', $sourceEvent->id)
        ->set('selectedStationIds', [$station->id])
        ->set('targetEventId', $targetConfig->id)
        ->set('copyEquipmentAssignments', false)
        ->call('checkForConflicts')
        ->assertHasNoErrors()
        ->assertSet('showConflicts', false)
        ->assertDispatched('stations-cloned');
});
