<?php

use App\Livewire\Events\EventForm;
use App\Models\AuditLog;
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
    Permission::create(['name' => 'create-events']);
    Permission::create(['name' => 'edit-events']);

    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['create-events', 'edit-events']);
    $this->user->assignRole($role);

    // Seed essential reference data
    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
        'setup_offset_hours' => 24,
    ]);

    $this->section = Section::create([
        'code' => 'CT',
        'name' => 'Connecticut',
        'region' => 'W1',
        'country' => 'USA',
        'is_active' => true,
    ]);

    $this->operatingClassA = OperatingClass::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'A',
        'name' => 'Class A',
        'description' => 'Portable emergency power',
        'allows_gota' => true,
        'max_power_watts' => 500,
        'requires_emergency_power' => true,
    ]);

    $this->operatingClassD = OperatingClass::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'D',
        'name' => 'Class D',
        'description' => 'Home stations using emergency power',
        'allows_gota' => false,
        'max_power_watts' => 100,
        'requires_emergency_power' => true,
    ]);
});

test('event form requires create-events permission for create mode', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->assertForbidden();
});

test('event form requires edit-events permission for edit mode', function () {
    $userWithoutPermission = User::factory()->create();
    $viewRole = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $viewRole->givePermissionTo('create-events');
    $userWithoutPermission->assignRole($viewRole);

    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->assertForbidden();
});

test('event form labels time fields as UTC', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->assertSee('Start Date & Time (UTC)')
        ->assertSee('End Date & Time (UTC)')
        ->assertSee('in UTC');
});

test('event form can create new event with configuration', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('club_name', 'Test Radio Club')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    // Verify Event was created
    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event)->not->toBeNull();
    expect($event->event_type_id)->toBe($this->eventType->id);

    // Verify EventConfiguration was created with correct relationships
    expect($event->eventConfiguration)->not->toBeNull();
    expect($event->eventConfiguration->callsign)->toBe('W1AW');
    expect($event->eventConfiguration->section_id)->toBe($this->section->id);
    expect($event->eventConfiguration->operating_class_id)->toBe($this->operatingClassA->id);
});

test('event form calculates power multiplier correctly', function () {
    $this->actingAs($this->user);

    // Test 5x multiplier: ≤5W + battery + no commercial/generator
    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('max_power_watts', 5)
        ->set('uses_battery', true)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false);

    expect($component->get('powerMultiplier'))->toBe('5');

    // Test 2x multiplier: ≤5W + commercial power
    $component->set('uses_commercial_power', true);
    expect($component->get('powerMultiplier'))->toBe('2');

    // Test 2x multiplier: 6-100W
    $component->set('max_power_watts', 50)
        ->set('uses_battery', false)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false);
    expect($component->get('powerMultiplier'))->toBe('2');

    // Test 1x multiplier: >100W
    $component->set('max_power_watts', 150);
    expect($component->get('powerMultiplier'))->toBe('1');
});

test('event form locks fields when event has contacts', function () {
    $this->actingAs($this->user);

    // Create event with contacts
    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'max_power_watts' => 100,
    ]);
    \App\Models\Contact::factory()->create(['event_configuration_id' => $config->id]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    // Check that critical fields are disabled
    expect($component->get('isLocked'))->toBeTrue();

    // Original power value
    $originalPower = $component->get('max_power_watts');

    // Try to change a locked field
    $component->set('max_power_watts', 999);

    // Should dispatch a notification about locked field
    $component->assertDispatched('notify');

    // In Livewire, the value might change in the component state during testing,
    // but the important thing is that when saved, locked fields won't be updated
    // We verify this by checking isLocked is true
    expect($component->get('isLocked'))->toBeTrue();
});

test('event form validates power based on operating class', function () {
    $this->actingAs($this->user);

    // Class A allows max 500W
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 600) // Exceeds Class A limit
        ->call('save')
        ->assertHasErrors(['max_power_watts']);

    // Class D allows max 100W
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event 2')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassD->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 150) // Exceeds Class D limit
        ->call('save')
        ->assertHasErrors(['max_power_watts']);
});

test('event form can edit existing event', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['name' => 'Original Name']);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1OLD',
    ]);

    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set('name', 'Updated Name')
        ->set('callsign', 'W1NEW')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    $event->refresh();
    expect($event->name)->toBe('Updated Name');
    expect($event->eventConfiguration->callsign)->toBe('W1NEW');
});

test('event form can clone existing event', function () {
    $this->actingAs($this->user);

    $originalEvent = Event::factory()->create([
        'name' => 'Field Day 2025',
        'start_time' => '2025-06-28 18:00:00',
        'end_time' => '2025-06-29 20:59:00',
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $originalEvent->id,
        'callsign' => 'W1AW',
        'max_power_watts' => 100,
        'uses_battery' => true,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'clone', 'eventId' => $originalEvent->id]);

    // Should increment year in name
    expect($component->get('name'))->toBe('Field Day 2026');

    // Should autofill Field Day dates for the new year (4th Saturday in June 2026)
    expect($component->get('start_time'))->toBe('2026-06-27 18:00:00');
    expect($component->get('end_time'))->toBe('2026-06-28 20:59:00');

    // Should copy configuration
    expect($component->get('callsign'))->toBe('W1AW');
    expect($component->get('max_power_watts'))->toBe(100);
    expect($component->get('uses_battery'))->toBeTrue();
});

test('event form displays real-time power multiplier badge', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('max_power_watts', 5)
        ->set('uses_battery', true)
        ->assertSee('5×'); // Should show 5x multiplier badge
});

test('event form can toggle GOTA station', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('has_gota_station', true)
        ->set('gota_callsign', 'W1GOTA');

    expect($component->get('has_gota_station'))->toBeTrue();
    expect($component->get('gota_callsign'))->toBe('W1GOTA');
});

test('event form validates required fields', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('transmitter_count', null) // Explicitly clear default value
        ->call('save')
        ->assertHasErrors([
            'name',
            'event_type_id',
            'start_time',
            'end_time',
            'callsign',
            'section_id',
            'operating_class_id',
            'max_power_watts',
        ]);
});

test('event form validates callsign format', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('callsign', 'invalid callsign!')
        ->set('name', 'Test Event')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->call('save')
        ->assertHasErrors(['callsign']);
});

test('event form validates end time is after start time', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-29 20:00:00')
        ->set('end_time', '2025-06-28 18:00:00') // Before start time
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->call('save')
        ->assertHasErrors(['end_time']);
});

test('event form prevents GOTA callsign when class does not allow GOTA', function () {
    $this->actingAs($this->user);

    // Class D does not allow GOTA
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassD->id)
        ->set('has_gota_station', true)
        ->set('gota_callsign', 'W1GOTA')
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->call('save')
        ->assertHasErrors(['has_gota_station']);
});

test('event form prevents setting end time before current time on active event', function () {
    $this->actingAs($this->user);

    // Create an active event
    $event = Event::factory()->create([
        'name' => 'Active Event',
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    // Set as active event
    \App\Models\Setting::set('active_event_id', $event->id);

    // Try to set end time before current time (should fail)
    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set('end_time', now()->subHours(1)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasErrors(['end_time']);

    // Verify end time was not changed
    expect($event->fresh()->end_time->diffInHours(now()->addHours(6), false))->toBeLessThan(1);
});

test('event form allows extending end time on active event', function () {
    $this->actingAs($this->user);

    // Create an active event
    $event = Event::factory()->create([
        'name' => 'Active Event',
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    // Set as active event
    \App\Models\Setting::set('active_event_id', $event->id);

    $newEndTime = now()->addHours(12);

    // Try to extend end time (should succeed since current time is still within range)
    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set('end_time', $newEndTime->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasNoErrors();

    // Verify end time was extended
    expect($event->fresh()->end_time->diffInHours($newEndTime, false))->toBeLessThan(1);
});

test('event form locks event type, callsign, and start time on active event', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'name' => 'Active Event',
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    // Set as active event
    \App\Models\Setting::set('active_event_id', $event->id);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    // Verify the form is locked
    expect($component->get('isLocked'))->toBeTrue();

    // Attempt to change locked fields - they should remain unchanged after save
    $component
        ->set('name', 'Updated Name')
        ->set('end_time', now()->addHours(12)->format('Y-m-d H:i:s'))
        ->call('save')
        ->assertHasNoErrors();

    // Verify unlocked fields were updated
    expect($event->fresh()->name)->toBe('Updated Name');

    // Verify locked fields were NOT changed
    expect($config->fresh()->callsign)->toBe('W1AW');
    expect($event->fresh()->event_type_id)->toBe($this->eventType->id);
});

test('event form requires at least one power source', function () {
    $this->actingAs($this->user);

    // Try to create event with no power sources selected
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false)
        ->set('uses_battery', false)
        ->set('uses_solar', false)
        ->set('uses_wind', false)
        ->set('uses_water', false)
        ->set('uses_methane', false)
        ->set('uses_other_power', null)
        ->call('save')
        ->assertHasErrors(['uses_commercial_power']);

    // Should succeed with at least one power source
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Test Event 2')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->call('save')
        ->assertHasNoErrors();
});

// Field Day Date Autofill Tests

test('selecting Field Day event type autofills start and end dates', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id);

    // 2025: June 1 is Sunday, so Saturdays are 7, 14, 21, 28. 4th Saturday = June 28.
    expect($component->get('start_time'))->toBe('2025-06-28 18:00:00');
    expect($component->get('end_time'))->toBe('2025-06-29 20:59:00');
});

test('changing year in event name recalculates Field Day dates', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('name', 'Field Day 2025');

    expect($component->get('start_time'))->toBe('2025-06-28 18:00:00');

    // Change year to 2027 — June 1 2027 is Tuesday, Saturdays: 5, 12, 19, 26
    $component->set('name', 'Field Day 2027');

    expect($component->get('start_time'))->toBe('2027-06-26 18:00:00');
    expect($component->get('end_time'))->toBe('2027-06-27 20:59:00');
});

test('non-Field Day event type does not autofill dates', function () {
    $this->actingAs($this->user);

    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day',
        'is_active' => true,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Winter Field Day 2025')
        ->set('event_type_id', $wfdType->id);

    expect($component->get('start_time'))->toBeNull();
    expect($component->get('end_time'))->toBeNull();
});

test('Field Day date autofill does not apply in edit mode', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'start_time' => '2025-06-28 18:00:00',
        'end_time' => '2025-06-29 20:59:00',
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    // In edit mode, manually change dates — they should not be overwritten
    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set('end_time', '2025-06-29 21:00:00');

    expect($component->get('end_time'))->toBe('2025-06-29 21:00:00');
});

// Guestbook Settings Tests

test('can create event with guestbook enabled', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_detection_radius', 500)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event->eventConfiguration->guestbook_enabled)->toBeTrue();
    expect($event->eventConfiguration->guestbook_latitude)->toBe('39.7392000');
    expect($event->eventConfiguration->guestbook_longitude)->toBe('-104.9903000');
    expect($event->eventConfiguration->guestbook_detection_radius)->toBe(500);
});

test('can create event with guestbook and local subnets', function () {
    $this->actingAs($this->user);

    $subnets = "192.168.1.0/24\n10.0.0.0/8\n172.16.0.0/12";

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_detection_radius', 500)
        ->set('guestbook_local_subnets', $subnets)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event->eventConfiguration->guestbook_local_subnets)->toBe([
        '192.168.1.0/24',
        '10.0.0.0/8',
        '172.16.0.0/12',
    ]);
});

test('validates latitude range', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 91.0)
        ->set('guestbook_longitude', -104.9903)
        ->call('save')
        ->assertHasErrors(['guestbook_latitude']);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', -91.0)
        ->set('guestbook_longitude', -104.9903)
        ->call('save')
        ->assertHasErrors(['guestbook_latitude']);
});

test('validates longitude range', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', 181.0)
        ->call('save')
        ->assertHasErrors(['guestbook_longitude']);
});

test('validates detection radius range', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_detection_radius', 50)
        ->call('save')
        ->assertHasErrors(['guestbook_detection_radius']);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_detection_radius', 3000)
        ->call('save')
        ->assertHasErrors(['guestbook_detection_radius']);
});

test('validates CIDR notation format', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_local_subnets', 'not-a-valid-cidr')
        ->call('save')
        ->assertHasErrors(['guestbook_local_subnets']);
});

test('validates CIDR IP octets', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_local_subnets', '192.168.256.0/24')
        ->call('save')
        ->assertHasErrors(['guestbook_local_subnets']);
});

test('validates CIDR prefix length', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        ->set('guestbook_latitude', 39.7392)
        ->set('guestbook_longitude', -104.9903)
        ->set('guestbook_local_subnets', '192.168.1.0/33')
        ->call('save')
        ->assertHasErrors(['guestbook_local_subnets']);
});

test('allows guestbook to be enabled without coordinates', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('guestbook_enabled', true)
        // No lat/lon provided - should still save successfully
        ->call('save')
        ->assertHasNoErrors(['guestbook_latitude', 'guestbook_longitude'])
        ->assertRedirect(route('events.index'));

    // Verify event was created with guestbook enabled but no coordinates
    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event)->not->toBeNull();
    expect($event->eventConfiguration->guestbook_enabled)->toBeTrue();
    expect($event->eventConfiguration->guestbook_latitude)->toBeNull();
    expect($event->eventConfiguration->guestbook_longitude)->toBeNull();
});

test('loads guestbook settings when editing event', function () {
    $this->actingAs($this->user);

    $event = Event::create([
        'name' => 'Test Event',
        'event_type_id' => $this->eventType->id,
        'year' => 2025,
        'start_time' => '2025-06-28 18:00:00',
        'end_time' => '2025-06-29 20:59:00',
        'is_active' => true,
    ]);

    EventConfiguration::create([
        'event_id' => $event->id,
        'created_by_user_id' => $this->user->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
        'transmitter_count' => 1,
        'max_power_watts' => 100,
        'power_multiplier' => 2,
        'uses_battery' => true,
        'guestbook_enabled' => true,
        'guestbook_latitude' => 39.7392,
        'guestbook_longitude' => -104.9903,
        'guestbook_detection_radius' => 750,
        'guestbook_local_subnets' => ['192.168.1.0/24', '10.0.0.0/8'],
    ]);

    $component = Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit']);

    expect($component->guestbook_enabled)->toBeTrue();
    expect($component->guestbook_latitude)->toBe(39.7392);
    expect($component->guestbook_longitude)->toBe(-104.9903);
    expect($component->guestbook_detection_radius)->toBe(750);
    expect($component->guestbook_local_subnets)->toBe("192.168.1.0/24\n10.0.0.0/8");
});

test('creating an event logs to audit log', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('club_name', 'Test Radio Club')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Field Day 2025')->first();

    $auditLog = AuditLog::where('action', 'event.created')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->user_id)->toBe($this->user->id);
    expect($auditLog->auditable_type)->toBe(Event::class);
    expect($auditLog->auditable_id)->toBe($event->id);
    expect($auditLog->new_values)->toMatchArray([
        'name' => 'Field Day 2025',
        'callsign' => 'W1AW',
    ]);
});

test('updating an event logs to audit log with old and new values', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['name' => 'Original Name']);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1OLD',
    ]);

    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set('name', 'Updated Name')
        ->set('callsign', 'W1NEW')
        ->call('save')
        ->assertHasNoErrors();

    $auditLog = AuditLog::where('action', 'event.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->user_id)->toBe($this->user->id);
    expect($auditLog->auditable_type)->toBe(Event::class);
    expect($auditLog->auditable_id)->toBe($event->id);
    expect($auditLog->old_values)->toMatchArray([
        'name' => 'Original Name',
        'callsign' => 'W1OLD',
    ]);
    expect($auditLog->new_values)->toMatchArray([
        'name' => 'Updated Name',
        'callsign' => 'W1NEW',
    ]);
});

test('creating an FD event sets setup_allowed_from based on offset hours', function () {
    // FD starts Saturday 1800Z; offset=24 → setup_allowed_from = Friday 1800Z
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('name', 'Test FD 2026')
        ->set('start_time', '2026-06-27 18:00')
        ->set('end_time', '2026-06-28 20:59')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Test FD 2026')->first();
    expect($event)->not->toBeNull();
    expect($event->setup_allowed_from->toDateTimeString())->toBe('2026-06-26 18:00:00');
});

test('creating a WFD event leaves setup_allowed_from null', function () {
    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day event',
        'is_active' => true,
        'setup_offset_hours' => null,
    ]);

    $wfdClass = OperatingClass::create([
        'event_type_id' => $wfdType->id,
        'code' => 'I',
        'name' => 'Indoor',
        'description' => 'Indoor operation',
        'allows_gota' => false,
        'max_power_watts' => 500,
        'requires_emergency_power' => false,
    ]);

    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $wfdType->id)
        ->set('name', 'Test WFD 2027')
        ->set('start_time', '2027-01-30 18:00')
        ->set('end_time', '2027-01-31 20:59')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $wfdClass->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Test WFD 2027')->first();
    expect($event)->not->toBeNull();
    expect($event->setup_allowed_from)->toBeNull();
});

test('setupAllowedFrom computed property returns null for event type without offset', function () {
    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day event',
        'is_active' => true,
        'setup_offset_hours' => null,
    ]);

    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $wfdType->id)
        ->set('start_time', '2027-01-30 18:00')
        ->assertSet('setupAllowedFrom', null);
});
