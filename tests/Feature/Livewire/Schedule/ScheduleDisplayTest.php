<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Schedule\ManageSchedule;
use App\Livewire\Schedule\MyShifts;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-shifts']);
    Permission::firstOrCreate(['name' => 'sign-up-shifts']);

    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addDays(2),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
    Setting::set('time_format', 'g:i:s A');

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['manage-shifts', 'sign-up-shifts']);

    $this->role = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'requires_confirmation' => false,
    ]);
});

test('no-shows do not count toward capacity or the Full filter', function () {
    $this->actingAs($this->user);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'capacity' => 1,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(4),
    ]);
    ShiftAssignment::factory()->noShow()->create(['shift_id' => $shift->id]);

    expect($shift->fresh()->has_capacity)->toBeTrue()
        ->and($shift->fresh()->slotsTaken())->toBe(0);

    $full = Livewire::test(ManageSchedule::class)->set('availability', 'full');
    expect($full->instance()->shifts->pluck('id'))->not->toContain($shift->id);

    $open = Livewire::test(ManageSchedule::class)->set('availability', 'unfilled');
    expect($open->instance()->shifts->pluck('id'))->toContain($shift->id);

    ShiftAssignment::factory()->create(['shift_id' => $shift->id]);

    $full = Livewire::test(ManageSchedule::class)->set('availability', 'full');
    expect($full->instance()->shifts->pluck('id'))->toContain($shift->id);
});

test('an overnight shift shows its end date', function () {
    $this->actingAs($this->user);

    $start = appNow()->addDay()->setTime(22, 0);
    $end = $start->copy()->addHours(4);
    Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => $start,
        'end_time' => $end,
    ]);

    $localStart = toLocalTime($start);
    $localEnd = toLocalTime($end);
    expect($localEnd->isSameDay($localStart))->toBeFalse();

    Livewire::test(ManageSchedule::class)
        ->set('timeFilter', 'all')
        ->assertSee($localEnd->format('M j, g:i A'));
});

test('an ended shift that was never checked in does not say check-in opens', function () {
    $this->actingAs($this->user);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(2),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    Livewire::test(MyShifts::class)
        ->set('timeFilter', 'all')
        ->assertSee('Shift ended')
        ->assertDontSee('Check-in opens');
});
