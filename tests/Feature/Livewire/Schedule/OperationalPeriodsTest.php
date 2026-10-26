<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Schedule\ManageSchedule;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperationalPeriod;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::create(['name' => 'manage-shifts']);

    $this->event = Event::factory()->create([
        'name' => 'City Marathon',
        'start_time' => appNow()->startOfHour()->subHours(2),
        'end_time' => appNow()->startOfHour()->addHours(14),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('manage-shifts');
});

test('the periods tab says the whole event is one period until periods are added', function () {
    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->set('activeTab', 'periods')
        ->assertSee('No operational periods')
        ->assertSee('The whole event is one period.');
});

test('each period has working edit and delete buttons', function () {
    OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Day 1 AM']);

    $html = Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->set('activeTab', 'periods')
        ->html();

    // Compiled into real buttons, not left as raw <x-button> tags.
    expect($html)->toMatch('/<button[^>]*aria-label="Edit Period"/')
        ->toMatch('/<button[^>]*Delete \\\\u0027Day 1 AM\\\\u0027\\?[^>]*aria-label="Delete Period"/');
});

test('a manager adds an operational period in local time', function () {
    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal')
        ->assertSet('periodStartTime', toLocalTime($this->event->start_time)->format('Y-m-d\TH:i'))
        ->set('periodName', 'Day 1 AM')
        ->set('periodStartTime', '2026-06-27 06:00')
        ->set('periodEndTime', '2026-06-27 14:00')
        ->call('savePeriod')
        ->assertHasNoErrors()
        ->assertSet('showPeriodModal', false)
        ->assertSee('Day 1 AM');

    $period = OperationalPeriod::sole();

    expect($period->event_configuration_id)->toBe($this->eventConfig->id)
        ->and($period->start_time->equalTo(Carbon::parse('2026-06-27 06:00', localTimezone())))->toBeTrue()
        ->and(AuditLog::where('action', 'operational_period.created')->exists())->toBeTrue();
});

test('a new period starts where the last one ended, for the same length', function () {
    OperationalPeriod::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'start_time' => '2026-06-27 06:00:00',
        'end_time' => '2026-06-27 14:00:00',
    ]);

    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal')
        ->assertSet('periodStartTime', toLocalTime(Carbon::parse('2026-06-27 14:00:00'))->format('Y-m-d\TH:i'))
        ->assertSet('periodEndTime', toLocalTime(Carbon::parse('2026-06-27 22:00:00'))->format('Y-m-d\TH:i'));
});

test('periods can\'t overlap', function () {
    OperationalPeriod::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Day 1 AM',
        'start_time' => Carbon::parse('2026-06-27 06:00', localTimezone()),
        'end_time' => Carbon::parse('2026-06-27 14:00', localTimezone()),
    ]);

    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal')
        ->set('periodName', 'Day 1 PM')
        ->set('periodStartTime', '2026-06-27 13:00')
        ->set('periodEndTime', '2026-06-27 22:00')
        ->call('savePeriod')
        ->assertHasErrors('periodStartTime');

    // Touching the boundary is fine.
    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal')
        ->set('periodName', 'Day 1 PM')
        ->set('periodStartTime', '2026-06-27 14:00')
        ->set('periodEndTime', '2026-06-27 22:00')
        ->call('savePeriod')
        ->assertHasNoErrors();

    expect(OperationalPeriod::count())->toBe(2);
});

test('a period has to end after it starts', function () {
    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal')
        ->set('periodName', 'Backwards')
        ->set('periodStartTime', '2026-06-27 14:00')
        ->set('periodEndTime', '2026-06-27 06:00')
        ->call('savePeriod')
        ->assertHasErrors(['periodEndTime' => 'after']);
});

test('a manager edits and deletes a period', function () {
    $period = OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Day 1 AM']);

    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('openPeriodModal', $period->id)
        ->assertSet('periodName', 'Day 1 AM')
        ->set('periodName', 'Morning')
        ->call('savePeriod')
        ->assertHasNoErrors()
        ->call('deletePeriod', $period->id);

    expect(OperationalPeriod::count())->toBe(0)
        ->and(AuditLog::where('action', 'operational_period.updated')->value('new_values')['name'] ?? null)->toBe('Morning')
        ->and(AuditLog::where('action', 'operational_period.deleted')->exists())->toBeTrue();
});

test('another event\'s period can\'t be edited or deleted', function () {
    $other = OperationalPeriod::factory()->create();

    Livewire::actingAs($this->admin)->test(ManageSchedule::class)
        ->call('deletePeriod', $other->id)
        ->assertNotFound();

    expect($other->fresh())->not->toBeNull();
});
