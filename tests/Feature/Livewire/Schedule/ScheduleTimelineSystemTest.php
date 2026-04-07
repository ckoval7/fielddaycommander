<?php

use App\Livewire\Schedule\ScheduleTimeline;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);

    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->role = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Operator',
        'icon' => 'o-radio',
        'color' => '#6366f1',
    ]);

    $this->shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHour(),
        'end_time' => appNow()->addHours(3),
        'is_open' => true,
        'capacity' => 5,
    ]);
});

test('system user cannot sign up for shifts', function () {
    $this->actingAs($this->systemUser);

    Livewire::test(ScheduleTimeline::class)
        ->call('signUp', $this->shift->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect(ShiftAssignment::where('user_id', $this->systemUser->id)->exists())->toBeFalse();
});

test('system user cannot check in to shifts', function () {
    $currentShift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subMinutes(10),
        'end_time' => appNow()->addHours(2),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $currentShift->id,
        'user_id' => $this->systemUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($this->systemUser);

    Livewire::test(ScheduleTimeline::class)
        ->call('checkIn', $assignment->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_SCHEDULED);
});

test('system user cannot check out of shifts', function () {
    $currentShift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $currentShift->id,
        'user_id' => $this->systemUser->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($this->systemUser);

    Livewire::test(ScheduleTimeline::class)
        ->call('checkOut', $assignment->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
});
