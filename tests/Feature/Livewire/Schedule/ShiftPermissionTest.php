<?php

use App\Livewire\Schedule\ManageSchedule;
use App\Livewire\Schedule\MyShifts;
use App\Livewire\Schedule\ScheduleTimeline;
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
    Permission::firstOrCreate(['name' => 'sign-up-shifts']);
    Permission::firstOrCreate(['name' => 'manage-shifts']);

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
        'start_time' => appNow()->subMinutes(10),
        'end_time' => appNow()->addHours(2),
        'is_open' => true,
        'capacity' => 5,
    ]);
});

test('user without sign-up-shifts cannot sign up for shifts via timeline', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ScheduleTimeline::class)
        ->call('signUp', $this->shift->id)
        ->assertForbidden();
});

test('user with sign-up-shifts can sign up for shifts via timeline', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('sign-up-shifts');

    $this->actingAs($user);

    Livewire::test(ScheduleTimeline::class)
        ->call('signUp', $this->shift->id)
        ->assertOk();

    expect(ShiftAssignment::where('user_id', $user->id)->exists())->toBeTrue();
});

test('user without sign-up-shifts cannot check in via my shifts', function () {
    $user = User::factory()->create();

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($user);

    Livewire::test(MyShifts::class)
        ->call('checkIn', $assignment->id)
        ->assertForbidden();

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_SCHEDULED);
});

test('SYSTEM user excluded from shift assignment dropdown', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-shifts');

    $this->actingAs($admin);

    $component = Livewire::test(ManageSchedule::class)
        ->call('openAssignModal', $this->shift->id);

    $users = $component->get('users');
    $userIds = collect($users)->pluck('id')->all();

    expect($userIds)->not->toContain($systemUser->id);
});
