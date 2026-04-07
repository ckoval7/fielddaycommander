<?php

use App\Livewire\Schedule\MyShifts;
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
});

test('system user cannot check in via my shifts', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subMinutes(10),
        'end_time' => appNow()->addHours(2),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->systemUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($this->systemUser);

    Livewire::test(MyShifts::class)
        ->call('checkIn', $assignment->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_SCHEDULED);
});

test('system user cannot check out via my shifts', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->systemUser->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($this->systemUser);

    Livewire::test(MyShifts::class)
        ->call('checkOut', $assignment->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
});

test('system user cannot cancel signup via my shifts', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHour(),
        'end_time' => appNow()->addHours(3),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->systemUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
    ]);

    $this->actingAs($this->systemUser);

    Livewire::test(MyShifts::class)
        ->call('cancelSignUp', $assignment->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect(ShiftAssignment::find($assignment->id))->not->toBeNull();
});
