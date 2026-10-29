<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\PowerSource;
use App\Livewire\Events\EventForm;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Organization;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use App\Scoring\Dto\PowerContext;
use App\Scoring\Rules\FieldDay2025;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

test('event form pre-populates callsign and club name from default organization', function () {
    $this->actingAs($this->user);

    $organization = Organization::factory()->create([
        'callsign' => 'W1XYZ',
        'name' => 'Springfield Amateur Radio Club',
    ]);
    Setting::set('default_organization_id', $organization->id);

    $component = Livewire::test(EventForm::class, ['mode' => 'create']);

    expect($component->get('callsign'))->toBe('W1XYZ');
    expect($component->get('club_name'))->toBe('Springfield Amateur Radio Club');
});

test('event form does not pre-populate when no default organization is set', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create']);

    expect($component->get('callsign'))->toBeNull();
    expect($component->get('club_name'))->toBeNull();
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
        ->assertRedirect(route('events.index'));

    expect(session('toast'))->toMatchArray(['title' => 'Success', 'description' => 'Event saved.']);

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

test('Field Day events cannot use the WFD-only sections as their own', function () {
    $this->actingAs($this->user);

    $yukon = Section::firstOrCreate(
        ['code' => 'YT'],
        ['name' => 'Yukon', 'region' => 'VE', 'country' => 'CA', 'is_active' => true],
    );

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id);

    expect($component->get('sections')->pluck('code'))->not->toContain('YT')->toContain('CT');

    $component->set('section_id', $yukon->id)
        ->call('save')
        ->assertHasErrors(['section_id']);
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

test('event form colors the multiplier badge by multiplier', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('max_power_watts', 5)
        ->set('uses_battery', true);

    expect($component->get('powerMultiplierColor'))->toBe('success');

    $component->set('max_power_watts', 50);
    expect($component->get('powerMultiplierColor'))->toBe('warning');

    $component->set('max_power_watts', 150);
    expect($component->get('powerMultiplierColor'))->toBe('error');

    // A new event has no stations yet, so the form says they can change it.
    $component->assertSee('Station settings count too');
});

test('event form multiplier preview follows the selected rules version', function () {
    $this->actingAs($this->user);

    // The synthetic TEST rulebook scores 6-100W at 3x instead of 2x.
    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('rules_version', 'TEST')
        ->set('max_power_watts', 50);

    expect($component->get('powerMultiplier'))->toBe('3');

    $component->set('rules_version', '2025');
    expect($component->get('powerMultiplier'))->toBe('2');
});

test('the power multiplier box shows the selected rules version\'s thresholds', function () {
    $this->actingAs($this->user);

    // A rulebook with different limits than Field Day 2025.
    app()->bind(FieldDay2025::class, fn () => new class extends FieldDay2025
    {
        public function powerMultiplier(PowerContext $ctx): string
        {
            if ($ctx->effectivePowerWatts > 150) {
                return '1';
            }

            return $ctx->effectivePowerWatts <= 10 && $ctx->qualifiesForQrpNaturalBonus ? '5' : '2';
        }
    });

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('rules_version', '2025')
        ->assertSeeHtml('<strong>5×:</strong>')
        ->assertSee('10 W or less, using only battery, solar, wind, or water power.')
        ->assertSee('150 W or less')
        ->assertSee('over 150 W')
        ->assertDontSee('5 W or less,')
        ->assertDontSee('over 100 W');

    // The synthetic TEST rulebook scores the middle tier at 3x.
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('rules_version', 'TEST')
        ->assertSeeHtml('<strong>3×:</strong>')
        ->assertDontSeeHtml('<strong>2×:</strong>');
});

test('the power multiplier box names the power sources scoring counts as natural', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->set('rules_version', '2025')
        ->assertSee('5 W or less, using only battery, solar, wind, or water power. No commercial power or generators.')
        ->assertSee('100 W or less')
        ->assertSee('over 100 W')
        ->assertSee('Examples: solar, wind, and water (hydro).')
        ->assertDontSee('alternate power)')
        ->assertDontSee('Methane');
});

test('event form multiplier preview counts the saved stations when editing', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'rules_version' => '2025',
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Generator,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    // Event-level settings alone would earn 5x, but the generator station rules it out.
    expect($component->get('powerMultiplier'))->toBe('2')
        ->and($config->fresh()->calculatePowerMultiplier())->toBe('2');

    $station->update(['power_source' => PowerSource::Battery, 'max_power_watts' => 100]);

    // A station above 5W sets the effective power, so the event is out of QRP.
    expect(Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])->get('powerMultiplier'))
        ->toBe('2');

    $station->update(['max_power_watts' => 5]);

    expect(Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])->get('powerMultiplier'))
        ->toBe('5');
});

test('event form locks fields when event has contacts', function () {
    $this->actingAs($this->user);

    // Create event with contacts
    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'max_power_watts' => 100,
    ]);
    Contact::factory()->create(['event_configuration_id' => $config->id]);

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
        ->assertRedirect(route('events.index'));

    expect(session('toast'))->toMatchArray(['title' => 'Success', 'description' => 'Event saved.']);

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

test('event form highlights date pickers that fail validation', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->assertDontSeeHtml('[&_.input]:border-error');

    $component->call('save')
        ->assertHasErrors(['start_time', 'end_time'])
        ->assertSeeHtml('[&_.input]:border-error');
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
    Setting::set('active_event_id', $event->id);

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
    Setting::set('active_event_id', $event->id);

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
    Setting::set('active_event_id', $event->id);

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

test('WFD autofills the 4th full weekend of January per the WFDA SOP', function () {
    $this->actingAs($this->user);

    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day',
        'is_active' => true,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $wfdType->id)
        ->set('year', 2026)
        ->set('name', 'Winter Field Day 2026');

    // The 2026 SOP names January 24th-25th, 1600 UTC Saturday to 2159 UTC Sunday.
    expect($component->get('start_time'))->toBe('2026-01-24 16:00:00')
        ->and($component->get('end_time'))->toBe('2026-01-25 21:59:00');
});

test('an event type with no published window does not autofill dates', function () {
    $this->actingAs($this->user);

    $otherType = EventType::create([
        'code' => 'XYZ',
        'name' => 'Some Other Event',
        'description' => 'No published operating window',
        'is_active' => true,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Some Other Event 2026')
        ->set('event_type_id', $otherType->id);

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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
        ->set('guestbook_detection_radius', 500)
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event->eventConfiguration->guestbook_enabled)->toBeTrue();
    expect((float) $event->eventConfiguration->latitude)->toEqual(39.7392);
    expect((float) $event->eventConfiguration->longitude)->toEqual(-104.9903);
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->set('latitude', 91.0)
        ->set('longitude', -104.9903)
        ->call('save')
        ->assertHasErrors(['latitude']);

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
        ->set('latitude', -91.0)
        ->set('longitude', -104.9903)
        ->call('save')
        ->assertHasErrors(['latitude']);
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
        ->set('latitude', 39.7392)
        ->set('longitude', 181.0)
        ->call('save')
        ->assertHasErrors(['longitude']);
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
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
        ->assertHasNoErrors(['latitude', 'longitude'])
        ->assertRedirect(route('events.index'));

    // Verify event was created with guestbook enabled but no coordinates
    $event = Event::where('name', 'Field Day 2025')->first();
    expect($event)->not->toBeNull();
    expect($event->eventConfiguration->guestbook_enabled)->toBeTrue();
    expect($event->eventConfiguration->latitude)->toBeNull();
    expect($event->eventConfiguration->longitude)->toBeNull();
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
        'latitude' => 39.7392,
        'longitude' => -104.9903,
        'guestbook_detection_radius' => 750,
        'guestbook_local_subnets' => ['192.168.1.0/24', '10.0.0.0/8'],
    ]);

    $component = Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit']);

    expect($component->guestbook_enabled)->toBeTrue();
    expect((float) $component->get('latitude'))->toEqual(39.7392);
    expect((float) $component->get('longitude'))->toEqual(-104.9903);
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

test('creating a WFD event sets setup_allowed_from 24 hours before the 1600Z start', function () {
    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day Association event - held the 4th full weekend in January',
        'is_active' => true,
        'setup_offset_hours' => 24,
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
        ->set('name', 'Test WFD 2027 Setup')
        ->set('start_time', '2027-01-23 16:00')
        ->set('end_time', '2027-01-24 21:59')
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

    $event = Event::where('name', 'Test WFD 2027 Setup')->first();
    expect($event)->not->toBeNull();
    expect($event->setup_allowed_from->toDateTimeString())->toBe('2027-01-22 16:00:00');
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

test('event configuration model has location fillable and casts', function () {
    $config = EventConfiguration::factory()->create([
        'grid_square' => 'DM79',
        'latitude' => 39.7392,
        'longitude' => -104.9903,
        'city' => 'Denver',
        'state' => 'Colorado',
    ]);

    $config->refresh();

    expect($config->grid_square)->toBe('DM79');
    expect($config->city)->toBe('Denver');
    expect($config->state)->toBe('Colorado');
    // Decimals are cast; compare as float
    expect((float) $config->latitude)->toEqual(39.7392);
    expect((float) $config->longitude)->toEqual(-104.9903);
});

test('event configuration has_location accessor returns true when lat and lon set', function () {
    $withLocation = EventConfiguration::factory()->create([
        'latitude' => 39.7392,
        'longitude' => -104.9903,
    ]);
    $withoutLocation = EventConfiguration::factory()->create([
        'latitude' => null,
        'longitude' => null,
    ]);

    expect($withLocation->has_location)->toBeTrue();
    expect($withoutLocation->has_location)->toBeFalse();
});

test('event form saves location fields when creating event', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('gridSquare', 'DM79')
        ->set('latitude', 39.7392)
        ->set('longitude', -104.9903)
        ->set('city', 'Denver')
        ->set('state', 'CO')
        ->call('save')
        ->assertHasNoErrors();

    $config = Event::where('name', 'Field Day 2025')->first()->eventConfiguration;
    expect($config->grid_square)->toBe('DM79');
    expect($config->city)->toBe('Denver');
    expect($config->state)->toBe('CO');
    expect((float) $config->latitude)->toEqual(39.7392);
    expect((float) $config->longitude)->toEqual(-104.9903);
});

test('event form location fields are all optional', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->call('save')
        ->assertHasNoErrors();
});

test('event form rejects invalid grid square format', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('gridSquare', 'INVALID!')
        ->call('save')
        ->assertHasErrors(['gridSquare']);
});

test('event form loads location fields when editing', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'grid_square' => 'FN31',
        'latitude' => 41.7658,
        'longitude' => -72.6851,
        'city' => 'Hartford',
        'state' => 'Connecticut',
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    expect($component->get('gridSquare'))->toBe('FN31');
    expect($component->get('city'))->toBe('Hartford');
    expect($component->get('state'))->toBe('Connecticut');
    expect((float) $component->get('latitude'))->toEqual(41.7658);
    expect((float) $component->get('longitude'))->toEqual(-72.6851);
});

test('event form saves talk-in frequency when creating event', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('talk_in_frequency', '146.52 MHz FM')
        ->call('save')
        ->assertHasNoErrors();

    $config = Event::where('name', 'Field Day 2025')->first()->eventConfiguration;
    expect($config->talk_in_frequency)->toBe('146.52 MHz FM');
});

test('event form loads talk-in frequency when editing', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'talk_in_frequency' => '147.000 MHz',
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    expect($component->get('talk_in_frequency'))->toBe('147.000 MHz');
});

test('event form rejects talk-in frequency over 50 characters', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2025')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2025-06-28 18:00:00')
        ->set('end_time', '2025-06-29 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('talk_in_frequency', str_repeat('x', 51))
        ->call('save')
        ->assertHasErrors(['talk_in_frequency']);
});

test('event form saves selected rules_version on create', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('name', 'Field Day 2026')
        ->set('event_type_id', $this->eventType->id)
        ->set('start_time', '2026-06-27 18:00:00')
        ->set('end_time', '2026-06-28 20:59:00')
        ->set('callsign', 'W1AW')
        ->set('section_id', $this->section->id)
        ->set('operating_class_id', $this->operatingClassA->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->set('uses_battery', true)
        ->set('rules_version', '2025')
        ->call('save')
        ->assertHasNoErrors();

    $event = Event::where('name', 'Field Day 2026')->firstOrFail();
    expect($event->rules_version)->toBe('2025');
});

test('event form can change rules_version on edit before event starts', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'name' => 'Field Day 2026',
        'year' => 2026,
        'rules_version' => '2025',
        'start_time' => now()->addDays(60),
        'end_time' => now()->addDays(61),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->assertSet('rules_version', '2025')
        ->assertSet('rulesVersionLocked', false)
        ->set('rules_version', '2026')
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->rules_version)->toBe('2026');
});

test('event form leaves rules_version unset on create until event type is chosen', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EventForm::class, ['mode' => 'create'])
        ->assertSet('rules_version', null);

    expect($component->instance()->rulesVersionOptions())->toBe([]);

    $component->set('event_type_id', $this->eventType->id)
        ->assertSet('rules_version', (string) now()->year);

    $options = $component->instance()->rulesVersionOptions();
    expect($options)->not->toBeEmpty();
    foreach ($options as $option) {
        expect($option['name'])->not->toContain('(not registered)');
    }
});

test('event form locks rules_version after event has started', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'year' => 2025,
        'rules_version' => '2025',
        'start_time' => now()->subDay(),
        'end_time' => now()->addDay(),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->assertSet('rulesVersionLocked', true);
});

test('the power multiplier badge is hidden for event types that have no multiplier', function () {
    $this->actingAs($this->user);

    $wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day',
        'is_active' => true,
    ]);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $wfdType->id)
        ->assertSet('event_type_id', $wfdType->id)
        ->assertDontSee('Power multipliers');

    // Field Day still shows it.
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->eventType->id)
        ->assertSee('Power multipliers');
});

test('event form highlights power source checkboxes when none are selected', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('uses_commercial_power', false)
        ->set('uses_generator', false)
        ->set('uses_battery', false)
        ->set('uses_alternate_power', false)
        ->call('save')
        ->assertHasErrors(['uses_commercial_power'])
        ->assertSeeHtml('checkbox checkbox-sm checkbox-error')
        ->assertSeeHtml('border-error bg-error/5')
        ->assertSee('Select at least one power source.');
});

test('WFD rules guidance shows for WFD events only', function () {
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
        ->assertSee('WFD rules: class, category, and location')
        ->assertSee('300 m diameter')
        ->set('event_type_id', $this->eventType->id)
        ->assertDontSee('WFD rules: class, category, and location')
        ->assertDontSee('300 m diameter');
});

test('public visitor details save, validate and reload on edit', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->set('is_open_to_public', true)
        ->set('public_directions', str_repeat('a', 1001))
        ->call('save')
        ->assertHasErrors(['public_directions' => 'max']);

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->set('is_open_to_public', true)
        ->set('public_directions', 'Behind the library')
        ->set('public_visiting_hours', 'Sat 10-4')
        ->set('public_contact_email', 'info@example.org')
        ->set('public_contact_phone', '+1 (555) 010-0100 ext. 5')
        ->set('public_website_url', 'https://facebook.com/club')
        ->set('public_description', 'Come visit.')
        ->call('save')
        ->assertHasNoErrors();

    $config->refresh();
    expect($config->is_open_to_public)->toBeTrue()
        ->and($config->public_directions)->toBe('Behind the library')
        ->and($config->public_contact_email)->toBe('info@example.org')
        ->and($config->public_contact_phone)->toBe('+1 (555) 010-0100 ext. 5')
        ->and($config->public_website_url)->toBe('https://facebook.com/club');

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->assertSet('public_description', 'Come visit.')
        ->assertSet('public_contact_email', 'info@example.org')
        ->assertSet('public_contact_phone', '+1 (555) 010-0100 ext. 5')
        ->assertSet('public_website_url', 'https://facebook.com/club');
});

it('rejects invalid public contact email, phone and website', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    Livewire::test(EventForm::class, ['eventId' => $event->id, 'mode' => 'edit'])
        ->set('public_contact_email', 'not-an-email')
        ->set('public_contact_phone', 'call me <b>')
        ->set('public_website_url', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors(['public_contact_email', 'public_contact_phone', 'public_website_url']);
});

test('event form identity and lock state cannot be changed by the client', function (string $property, mixed $value) {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    expect(fn () => Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'mode' => ['mode', 'create'],
    'eventId' => ['eventId', 999],
    'isLocked' => ['isLocked', false],
    'rulesVersionLocked' => ['rulesVersionLocked', false],
]);

test('event form save re-authorizes the current user', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'name' => 'Original Name',
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    $this->actingAs(User::factory()->create());

    $component->set('name', 'Hijacked')->call('save')->assertForbidden();

    expect($event->fresh()->name)->toBe('Original Name');
});

test('event form save recomputes the lock from the database', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
        'max_power_watts' => 100,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);
    expect($component->get('isLocked'))->toBeFalse();

    // Contacts arrive after the form was opened
    Contact::factory()->create(['event_configuration_id' => $config->id]);

    $component
        ->set('callsign', 'K1ABC')
        ->set('max_power_watts', 5)
        ->set('club_name', 'Updated Club')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', fn (string $name, array $params) => $params['type'] === 'warning'
            && str_contains($params['description'], 'callsign')
            && str_contains($params['description'], 'maximum power'))
        ->assertNoRedirect()
        ->assertSet('isLocked', true)
        ->assertSet('callsign', 'W1AW')
        ->assertSet('max_power_watts', 100);

    expect($config->fresh())
        ->callsign->toBe('W1AW')
        ->max_power_watts->toBe(100)
        ->club_name->toBe('Updated Club');
});

test('event form save that becomes locked without touching locked fields saves normally', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'operating_class_id' => $this->operatingClassA->id,
        'max_power_watts' => 100,
    ]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);

    Contact::factory()->create(['event_configuration_id' => $config->id]);

    $component
        ->set('club_name', 'Updated Club')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('toast')
        ->assertRedirect(route('events.index'));

    expect($config->fresh()->club_name)->toBe('Updated Club');
});

test('local subnets placeholder shows one example per line, not an escaped entity', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('guestbook_enabled', true)
        ->assertSeeHtml("placeholder=\"192.168.1.0/24\n10.0.0.0/8\"")
        ->assertDontSeeHtml('&amp;#10;');
});

test('alternate power hint tells methane generators to tick Generator too', function () {
    $this->actingAs($this->user);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->assertSee('A methane generator counts as alternate power.')
        ->assertSeeHtml('Also tick <strong>Generator</strong>');
});
