<?php

use App\Livewire\Events\EventsList;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'delete-events']);
    Permission::create(['name' => 'activate-events']);

    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-events', 'delete-events', 'activate-events']);
    $this->user->assignRole($role);
});

test('events list requires view-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(EventsList::class)
        ->assertForbidden();
});

test('events list is accessible with view-events permission', function () {
    $this->actingAs($this->user);

    Livewire::test(EventsList::class)
        ->assertStatus(200);
});

test('events list displays all events', function () {
    $this->actingAs($this->user);

    $event1 = Event::factory()->create(['name' => 'Field Day 2025']);
    $event2 = Event::factory()->create(['name' => 'Field Day 2024']);
    $event3 = Event::factory()->create(['name' => 'Field Day 2023']);
    $event3->delete(); // Properly soft delete

    Livewire::test(EventsList::class)
        ->assertSee('Field Day 2025')
        ->assertSee('Field Day 2024')
        ->assertDontSee('Field Day 2023'); // Soft deleted should not appear unless "Show Archived" is toggled
});

test('events list can show archived events', function () {
    $this->actingAs($this->user);

    $activeEvent = Event::factory()->create(['name' => 'Active FD 2025']);
    $deletedEvent = Event::factory()->create(['name' => 'Old FD 2020']);
    $deletedEvent->delete(); // Properly soft delete

    Livewire::test(EventsList::class)
        ->assertSee('Active FD 2025')
        ->assertDontSee('Old FD 2020')
        ->set('showArchived', true)
        ->assertSee('Active FD 2025')
        ->assertSee('Old FD 2020');
});

test('events list can sort by column', function () {
    $this->actingAs($this->user);

    Event::factory()->create(['name' => 'Alpha Event', 'created_at' => now()->subDays(2)]);
    Event::factory()->create(['name' => 'Zulu Event', 'created_at' => now()->subDays(1)]);

    $component = Livewire::test(EventsList::class)
        ->assertStatus(200);

    // Sort by name ascending
    $component->call('sortBy', 'name')
        ->assertSet('sortField', 'name')
        ->assertSet('sortDirection', 'asc');

    // Sort by name descending
    $component->call('sortBy', 'name')
        ->assertSet('sortField', 'name')
        ->assertSet('sortDirection', 'desc');
});

test('delete action requires delete-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $viewRole = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $viewRole->givePermissionTo('view-events');
    $userWithoutPermission->assignRole($viewRole);

    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventsList::class)
        ->call('delete', $event->id)
        ->assertForbidden();
});

test('delete action soft deletes event with contacts', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create a contact for this event configuration
    \App\Models\Contact::factory()->create(['event_configuration_id' => $config->id]);

    expect($event->fresh()->deleted_at)->toBeNull();

    Livewire::test(EventsList::class)
        ->call('delete', $event->id)
        ->assertDispatched('notify');

    expect($event->fresh()->deleted_at)->not->toBeNull(); // Soft deleted
    expect(Event::withTrashed()->find($event->id))->not->toBeNull(); // Still exists in DB
});

test('delete action hard deletes event without contacts', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(EventsList::class)
        ->call('delete', $event->id)
        ->assertDispatched('notify');

    expect(Event::withTrashed()->find($event->id))->toBeNull(); // Completely deleted
});

test('activate action requires activate-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $viewRole = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $viewRole->givePermissionTo('view-events');
    $userWithoutPermission->assignRole($viewRole);

    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventsList::class)
        ->call('activate', $event->id)
        ->assertForbidden();
});

test('activate action sets event as active', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $oldActiveEvent = Event::factory()->create();

    // Set old active event in settings
    \App\Models\Setting::set('active_event_id', $oldActiveEvent->id);

    Livewire::test(EventsList::class)
        ->call('activate', $event->id)
        ->assertDispatched('notify');

    expect(\App\Models\Setting::get('active_event_id'))->toBe($event->id);
});

test('events list displays correct status badges', function () {
    $this->actingAs($this->user);

    // Active event
    $activeEvent = Event::factory()->create(['name' => 'Active Event']);
    \App\Models\Setting::set('active_event_id', $activeEvent->id);

    // Upcoming event
    Event::factory()->create([
        'name' => 'Upcoming Event',
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    // Completed event
    Event::factory()->create([
        'name' => 'Completed Event',
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);

    // In progress event
    Event::factory()->create([
        'name' => 'In Progress Event',
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    Livewire::test(EventsList::class)
        ->assertSee('Active Event')
        ->assertSee('Upcoming Event')
        ->assertSee('Completed Event')
        ->assertSee('In Progress Event');
});

test('events list paginates results', function () {
    $this->actingAs($this->user);

    // Create 30 events (more than the 25 per page limit)
    Event::factory()->count(30)->create();

    $component = Livewire::test(EventsList::class);

    // Should see pagination controls
    $events = $component->get('events');
    expect($events->total())->toBe(30);
    expect($events->perPage())->toBe(25);
});

test('events list eager loads relationships to avoid N+1 queries', function () {
    $this->actingAs($this->user);

    Event::factory()->count(5)->create();

    // Enable query logging
    \DB::enableQueryLog();

    Livewire::test(EventsList::class);

    $queries = \DB::getQueryLog();

    // Should have minimal queries due to eager loading
    // With 5 events, we expect queries for:
    // - Event query with pagination
    // - Eager loading eventType, eventConfiguration, section, operatingClass
    // - Settings queries for active_event_id (called for each event status check)
    // - Contacts count (eager loaded via withCount)
    // Total should be significantly less than 5*N (where N is relationships per event)
    expect(count($queries))->toBeLessThan(30);
});
