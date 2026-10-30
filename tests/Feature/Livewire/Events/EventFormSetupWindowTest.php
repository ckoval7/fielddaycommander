<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Events\EventForm;
use App\Models\Contact;
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

    Permission::create(['name' => 'create-events']);
    Permission::create(['name' => 'edit-events']);
    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['create-events', 'edit-events']);
    $this->user->assignRole($role);

    $this->fdType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
        'setup_offset_hours' => 42,
    ]);
    $this->wfdType = EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day event',
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

    $this->fdClass = OperatingClass::create([
        'event_type_id' => $this->fdType->id,
        'code' => 'A',
        'name' => 'Class A',
        'description' => 'Portable emergency power',
        'allows_gota' => true,
        'max_power_watts' => 500,
        'requires_emergency_power' => true,
    ]);
    $this->wfdClass = OperatingClass::create([
        'event_type_id' => $this->wfdType->id,
        'code' => 'I',
        'name' => 'Indoor',
        'description' => 'Indoor operation',
        'allows_gota' => false,
        'max_power_watts' => 500,
        'requires_emergency_power' => false,
    ]);

    $this->actingAs($this->user);

    $this->createEvent = function (string $name, EventType $type, OperatingClass $class, string $start, string $end, bool $early = false): Event {
        $component = Livewire::test(EventForm::class, ['mode' => 'create'])
            ->set('event_type_id', $type->id)
            ->set('name', $name)
            ->set('start_time', $start)
            ->set('end_time', $end)
            ->set('callsign', 'W1AW')
            ->set('section_id', $this->section->id)
            ->set('operating_class_id', $class->id)
            ->set('transmitter_count', 1)
            ->set('max_power_watts', 100)
            ->set('uses_battery', true);

        if ($early) {
            $component->set('setup_started_early', true);
        }

        $component->call('save')->assertHasNoErrors();

        return Event::where('name', $name)->firstOrFail();
    };
});

test('an FD event that starts at another hour still opens setup at 0000Z Friday', function () {
    $event = ($this->createEvent)('FD Late 2026', $this->fdType, $this->fdClass, '2026-06-27 20:00', '2026-06-28 20:59');

    expect($event->setup_allowed_from->toDateTimeString())->toBe('2026-06-26 00:00:00');
});

test('the FD form previews setup opening at 0000Z Friday', function () {
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->fdType->id)
        ->set('start_time', '2026-06-27 18:00')
        ->assertSee('Friday, June 26, 2026 at 0000z');
});

test('the setup started early toggle shows for Field Day only', function () {
    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->fdType->id)
        ->set('operating_class_id', $this->fdClass->id)
        ->assertSee('Setup began before 1800 UTC Saturday')
        ->assertSeeHtml('wire:model="setup_started_early"')
        ->set('event_type_id', $this->wfdType->id)
        ->assertDontSee('Setup began before 1800 UTC Saturday');
});

test('Field Day classes other than A, B and F see their fixed 24 hours instead of the toggle', function () {
    $classD = OperatingClass::create([
        'event_type_id' => $this->fdType->id,
        'code' => 'D',
        'name' => 'Class D',
        'description' => 'Home station',
        'allows_gota' => false,
        'requires_emergency_power' => false,
    ]);

    Livewire::test(EventForm::class, ['mode' => 'create'])
        ->set('event_type_id', $this->fdType->id)
        ->set('operating_class_id', $classD->id)
        ->assertDontSeeHtml('wire:model="setup_started_early"')
        ->assertSee('24 consecutive hours');
});

test('a Field Day class other than A, B or F never saves the toggle', function () {
    $classE = OperatingClass::create([
        'event_type_id' => $this->fdType->id,
        'code' => 'E',
        'name' => 'Class E',
        'description' => 'Home station on emergency power',
        'allows_gota' => false,
        'requires_emergency_power' => true,
    ]);

    $event = ($this->createEvent)('FD Class E 2026', $this->fdType, $classE, '2026-06-27 18:00', '2026-06-28 20:59', true);

    expect($event->setup_started_early)->toBeFalse();
});

test('an FD event saves the setup started early toggle, off by default', function () {
    $early = ($this->createEvent)('FD Early 2026', $this->fdType, $this->fdClass, '2026-06-27 18:00', '2026-06-28 20:59', true);
    $normal = ($this->createEvent)('FD Normal 2026', $this->fdType, $this->fdClass, '2026-06-27 18:00', '2026-06-28 20:59');

    expect($early->setup_started_early)->toBeTrue()
        ->and($normal->setup_started_early)->toBeFalse();
});

test('a Winter Field Day event never saves the toggle and keeps its 24-hour setup', function () {
    $event = ($this->createEvent)('WFD Early 2027', $this->wfdType, $this->wfdClass, '2027-01-23 16:00', '2027-01-24 21:59', true);

    expect($event->setup_started_early)->toBeFalse()
        ->and($event->setup_allowed_from->toDateTimeString())->toBe('2027-01-22 16:00:00');
});

test('the toggle can still be changed once contacts lock the event', function () {
    $event = Event::factory()->create(['event_type_id' => $this->fdType->id]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'operating_class_id' => $this->fdClass->id,
        'section_id' => $this->section->id,
        'max_power_watts' => 100,
    ]);
    Contact::factory()->create(['event_configuration_id' => $config->id]);

    $component = Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id]);
    expect($component->get('isLocked'))->toBeTrue()
        ->and($component->get('setup_started_early'))->toBeFalse();

    $component->set('setup_started_early', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->setup_started_early)->toBeTrue();

    Livewire::test(EventForm::class, ['mode' => 'edit', 'eventId' => $event->id])
        ->assertSet('setup_started_early', true);
});

test('cloning an event turns setup started early back off', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->fdType->id,
        'name' => 'Field Day 2025',
        'setup_started_early' => true,
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(EventForm::class, ['mode' => 'clone', 'eventId' => $event->id])
        ->assertSet('setup_started_early', false);
});
