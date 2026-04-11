<?php

use App\Livewire\Stations\StationsList;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'view-stations']);
    Permission::create(['name' => 'manage-stations']);

    $role = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('view-stations');
    $this->user->assignRole($role);

    // Shared event infrastructure
    $this->event = Event::factory()->create();
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
});

// Authorization Tests

test('stations list requires view-stations permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(StationsList::class)
        ->assertForbidden();
});

test('stations list is accessible with view-stations permission', function () {
    $this->actingAs($this->user);

    Livewire::test(StationsList::class)
        ->assertStatus(200);
});

test('users without manage-stations cannot see edit delete buttons', function () {
    $this->actingAs($this->user);

    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Test Station',
    ]);

    $component = Livewire::test(StationsList::class);

    // User without manage-stations should not see Edit or Delete buttons
    $html = $component->html();
    expect($html)->not->toContain('wire:click="deleteStation('.$station->id.')"');
    expect($html)->not->toContain(route('stations.edit', $station));
});

test('users with manage-stations can see edit delete buttons', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    $component = Livewire::test(StationsList::class);

    // User with manage-stations should see Edit and Delete buttons
    $html = $component->html();
    expect($html)->toContain('wire:click="deleteStation('.$station->id.')"');
    expect($html)->toContain(route('stations.edit', $station));
});

// Event Filter Tests

test('event filter dropdown shows all non-deleted events', function () {
    $this->actingAs($this->user);

    $event1 = Event::factory()->create(['name' => 'Field Day 2025']);
    $event2 = Event::factory()->create(['name' => 'Field Day 2024']);
    $deletedEvent = Event::factory()->create(['name' => 'Field Day 2023']);
    $deletedEvent->delete(); // Soft delete

    $component = Livewire::test(StationsList::class);

    $events = $component->get('events');
    // +1 for the shared event from beforeEach
    expect($events->pluck('name')->toArray())->toContain('Field Day 2025', 'Field Day 2024');
    expect($events->pluck('name')->toArray())->not->toContain('Field Day 2023');
});

test('event filter defaults to active event', function () {
    $this->actingAs($this->user);

    // active_event_id is already set to $this->event in beforeEach
    Livewire::test(StationsList::class)
        ->assertSet('eventFilter', $this->event->id);
});

test('changing event filter updates station list', function () {
    $this->actingAs($this->user);

    $station1 = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Alpha',
    ]);

    $event2 = Event::factory()->create(['name' => 'Event 2']);
    $config2 = EventConfiguration::factory()->create(['event_id' => $event2->id]);
    $station2 = Station::factory()->create([
        'event_configuration_id' => $config2->id,
        'name' => 'Station Beta',
    ]);

    Livewire::test(StationsList::class)
        ->assertSet('eventFilter', $this->event->id)
        ->assertSee('Station Alpha')
        ->assertDontSee('Station Beta')
        ->set('eventFilter', $event2->id)
        ->assertSee('Station Beta')
        ->assertDontSee('Station Alpha');
});

test('stats update when event filter changes', function () {
    $this->actingAs($this->user);

    Station::factory()->count(3)->create(['event_configuration_id' => $this->eventConfig->id]);

    $event2 = Event::factory()->create();
    $config2 = EventConfiguration::factory()->create(['event_id' => $event2->id]);
    Station::factory()->count(5)->create(['event_configuration_id' => $config2->id]);

    $component = Livewire::test(StationsList::class)
        ->assertSet('eventFilter', $this->event->id);

    $stats = $component->get('stats');
    expect($stats['total'])->toBe(3);

    $component->set('eventFilter', $event2->id);
    $stats = $component->get('stats');
    expect($stats['total'])->toBe(5);
});

// Station Display Tests

test('station cards display correctly with all details', function () {
    $this->actingAs($this->user);

    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => '20m SSB Station',
        'power_source_description' => 'Solar + Battery',
        'max_power_watts' => 100,
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('20m SSB Station')
        ->assertSee('Solar + Battery')
        ->assertSee('100W');
});

test('gota badge shows for gota stations', function () {
    $this->actingAs($this->user);

    Station::factory()->gota()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'GOTA Station',
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('GOTA Station')
        ->assertSee('GOTA');
});

test('vhf-only badge shows for vhf stations', function () {
    $this->actingAs($this->user);

    Station::factory()->vhfOnly()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'VHF Station',
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('VHF Station')
        ->assertSee('VHF');
});

test('satellite badge shows for satellite stations', function () {
    $this->actingAs($this->user);

    Station::factory()->satellite()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('Satellite');
});

test('active badge shows for stations with active sessions', function () {
    $this->actingAs($this->user);

    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Active Station',
    ]);

    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'end_time' => null,
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('Active Station')
        ->assertSee('Active');
});

test('primary radio details display correctly', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $radio->id,
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('Yaesu')
        ->assertSee('FT-991A');
});

test('equipment count badge shows correct count', function () {
    $this->actingAs($this->user);

    $station = Station::withoutEvents(fn () => Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Multi Equipment Station',
    ]));

    // Attach additional equipment via pivot table
    $equipment1 = Equipment::factory()->create(['type' => 'antenna']);
    $equipment2 = Equipment::factory()->create(['type' => 'antenna']);
    $equipment3 = Equipment::factory()->create(['type' => 'accessory']);

    $station->additionalEquipment()->attach($equipment1->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);
    $station->additionalEquipment()->attach($equipment2->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);
    $station->additionalEquipment()->attach($equipment3->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(StationsList::class);
    $stations = $component->get('stations');

    expect($stations->first()->additional_equipment_count)->toBe(3);
});

// Delete Action Tests

test('can delete station without contacts (hard delete)', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Empty Station',
    ]);

    expect(Station::where('id', $station->id)->exists())->toBeTrue();

    Livewire::test(StationsList::class)
        ->call('deleteStation', $station->id)
        ->assertDispatched('notify');

    // Should be permanently deleted
    expect(Station::withTrashed()->find($station->id))->toBeNull();
});

test('can delete station with contacts (soft delete)', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'end_time' => now()->subHours(1),
    ]);

    // Create a contact for this station
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
    ]);

    Livewire::test(StationsList::class)
        ->call('deleteStation', $station->id)
        ->assertDispatched('notify');

    // Should be soft deleted
    expect($station->fresh()->deleted_at)->not->toBeNull();
    expect(Station::withTrashed()->find($station->id))->not->toBeNull();
});

test('cannot delete station with active sessions', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    // Create an active operating session
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'end_time' => null, // Active session
    ]);

    Livewire::test(StationsList::class)
        ->call('deleteStation', $station->id)
        ->assertForbidden();

    // Station should still exist
    expect(Station::find($station->id))->not->toBeNull();
});

test('delete confirmation modal shows', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    Livewire::test(StationsList::class)
        ->assertSee('Are you sure');
});

test('successful delete shows toast notification', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Test Station',
    ]);

    Livewire::test(StationsList::class)
        ->call('deleteStation', $station->id)
        ->assertDispatched('notify');
});

// End Sessions Tests

test('can end active sessions for a station', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    $session = OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
    ]);

    Livewire::test(StationsList::class)
        ->call('endSessions', $station->id)
        ->assertDispatched('toast');

    $session->refresh();
    expect($session->end_time)->not->toBeNull();
});

test('end sessions requires manage-stations permission', function () {
    $this->actingAs($this->user);

    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
    ]);

    Livewire::test(StationsList::class)
        ->call('endSessions', $station->id)
        ->assertForbidden();
});

test('end sessions button visible only for active stations', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage-stations');

    $activeStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Active One',
    ]);

    OperatingSession::factory()->active()->create([
        'station_id' => $activeStation->id,
    ]);

    $inactiveStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Inactive One',
    ]);

    $html = Livewire::test(StationsList::class)
        ->html();

    expect($html)->toContain('wire:click="endSessions('.$activeStation->id.')"');
    expect($html)->not->toContain('wire:click="endSessions('.$inactiveStation->id.')"');
});

// Search/Pagination Tests

test('pagination shows when more than 12 stations', function () {
    $this->actingAs($this->user);

    Station::factory()->count(15)->create(['event_configuration_id' => $this->eventConfig->id]);

    $component = Livewire::test(StationsList::class);

    $stations = $component->get('stations');
    expect($stations->total())->toBe(15);
    expect($stations->perPage())->toBe(12);
});

test('pagination resets on filter change', function () {
    $this->actingAs($this->user);

    Station::factory()->count(20)->create(['event_configuration_id' => $this->eventConfig->id]);

    $event2 = Event::factory()->create();
    $config2 = EventConfiguration::factory()->create(['event_id' => $event2->id]);
    Station::factory()->count(5)->create(['event_configuration_id' => $config2->id]);

    $component = Livewire::test(StationsList::class);

    // Navigate to page 2
    $stations = $component->get('stations');
    expect($stations->currentPage())->toBe(1);

    // Change event filter should reset to page 1
    $component->set('eventFilter', $event2->id);

    $stations = $component->get('stations');
    expect($stations->currentPage())->toBe(1);
});

// Empty States

test('shows no event selected when no event selected', function () {
    $this->actingAs($this->user);

    Livewire::test(StationsList::class)
        ->set('eventFilter', null)
        ->assertSee('No Event Selected');
});

test('shows no stations configured when event has no stations', function () {
    $this->actingAs($this->user);

    // The shared event has no stations by default
    Livewire::test(StationsList::class)
        ->assertSee('No stations configured');
});

test('stats show zeros when no event selected', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(StationsList::class)
        ->set('eventFilter', null);

    $stats = $component->get('stats');
    expect($stats['total'])->toBe(0);
    expect($stats['active'])->toBe(0);
    expect($stats['equipment_count'])->toBe(0);
});

test('stats calculate active stations correctly', function () {
    $this->actingAs($this->user);

    // Create 5 stations total
    $station1 = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $station2 = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    Station::factory()->count(3)->create(['event_configuration_id' => $this->eventConfig->id]);

    // 2 active sessions
    OperatingSession::factory()->create([
        'station_id' => $station1->id,
        'end_time' => null,
    ]);
    OperatingSession::factory()->create([
        'station_id' => $station2->id,
        'end_time' => null,
    ]);

    $component = Livewire::test(StationsList::class);
    $stats = $component->get('stats');

    expect($stats['total'])->toBe(5);
    expect($stats['active'])->toBe(2);
});

test('stats calculate equipment count correctly', function () {
    $this->actingAs($this->user);

    $station1 = Station::withoutEvents(fn () => Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]));
    $station2 = Station::withoutEvents(fn () => Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]));

    // Station 1: 2 additional equipment
    $equipment1 = Equipment::factory()->create();
    $equipment2 = Equipment::factory()->create();
    $station1->additionalEquipment()->attach($equipment1->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);
    $station1->additionalEquipment()->attach($equipment2->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);

    // Station 2: 3 additional equipment
    $equipment3 = Equipment::factory()->create();
    $equipment4 = Equipment::factory()->create();
    $equipment5 = Equipment::factory()->create();
    $station2->additionalEquipment()->attach($equipment3->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);
    $station2->additionalEquipment()->attach($equipment4->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);
    $station2->additionalEquipment()->attach($equipment5->id, [
        'event_id' => $this->event->id,
        'status' => 'committed',
        'assigned_by_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(StationsList::class);
    $stats = $component->get('stats');

    expect($stats['equipment_count'])->toBe(5); // 2 + 3
});
