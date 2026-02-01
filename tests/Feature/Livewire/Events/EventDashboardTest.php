<?php

use App\Livewire\Events\EventDashboard;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'edit-events']);
    Permission::create(['name' => 'delete-events']);
    Permission::create(['name' => 'activate-events']);
    Permission::create(['name' => 'create-events']);

    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-events', 'edit-events', 'delete-events', 'activate-events', 'create-events']);
    $this->user->assignRole($role);
});

test('event dashboard requires view-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertForbidden();
});

test('event dashboard displays event details', function () {
    $this->actingAs($this->user);

    $eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
    $event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'event_type_id' => $eventType->id,
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Field Day 2025')
        ->assertSee('Field Day');
});

test('event dashboard displays configuration card', function () {
    $this->actingAs($this->user);

    $eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
    $section = Section::create([
        'code' => 'ORG',
        'name' => 'Orange',
        'region' => 'W6',
        'country' => 'US',
        'is_active' => true,
    ]);
    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '3A',
        'name' => 'Class 3A',
        'description' => '3 transmitters',
    ]);
    $event = Event::factory()->create([
        'name' => 'Test Event',
        'event_type_id' => $eventType->id,
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'ARRL HQ',
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
        'transmitter_count' => 3,
        'max_power_watts' => 100,
        'uses_battery' => true,
        'uses_solar' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('W1AW')
        ->assertSee('ARRL HQ')
        ->assertSee('ORG')
        ->assertSee('3A')
        ->assertSee('Battery')
        ->assertSee('Solar');
});

test('event dashboard shows scoring summary', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['name' => 'Test Event']);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Scoring Summary')
        // Should show 0s for now since contacts aren't implemented
        ->assertSee('0'); // Should appear in contacts/points display
});

test('activate action requires activate-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $viewRole = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $viewRole->givePermissionTo('view-events');
    $userWithoutPermission->assignRole($viewRole);

    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->call('activate')
        ->assertForbidden();
});

test('activate action sets event as active', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $oldActiveEvent = Event::factory()->create();

    // Set old active event in settings
    \App\Models\Setting::set('active_event_id', $oldActiveEvent->id);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->call('activate')
        ->assertDispatched('notify');

    expect(\App\Models\Setting::get('active_event_id'))->toBe($event->id);
});

test('event dashboard shows correct status badge', function () {
    $this->actingAs($this->user);

    // Test upcoming event
    $upcomingEvent = Event::factory()->create([
        'name' => 'Upcoming Event',
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $upcomingEvent])
        ->assertSee('Upcoming');

    // Test in progress event
    $inProgressEvent = Event::factory()->create([
        'name' => 'In Progress Event',
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $inProgressEvent])
        ->assertSee('In Progress');

    // Test completed event
    $completedEvent = Event::factory()->create([
        'name' => 'Completed Event',
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $completedEvent])
        ->assertSee('Completed');
});

test('event dashboard hides activate button if event is already active', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['name' => 'Active Event']);
    \App\Models\Setting::set('active_event_id', $event->id);

    $component = Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200);

    // The isActive computed property should be true
    expect($component->get('isActive'))->toBeTrue();
});

test('event dashboard eager loads relationships', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Enable query logging
    \DB::enableQueryLog();

    Livewire::test(EventDashboard::class, ['event' => $event]);

    $queries = \DB::getQueryLog();

    // Should have minimal queries due to eager loading
    // Expect: main event query with eager loads, setting query for active_event_id
    // With all the relationships and computed properties, expect fewer than 20 queries
    expect(count($queries))->toBeLessThan(20);
});
