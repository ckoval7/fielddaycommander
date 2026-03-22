<?php

use App\Livewire\Schedule\ScheduleTimeline;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => now()->subHour(),
        'end_time' => now()->addDay(),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->user = User::factory()->create([
        'first_name' => 'Test',
        'last_name' => 'Operator',
    ]);
});

describe('rendering', function () {
    test('renders the schedule timeline page', function () {
        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->assertStatus(200)
            ->assertSee('Schedule');
    });

    test('shows event name when event exists', function () {
        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->assertSee('Field Day 2026');
    });

    test('shows info message when no event config', function () {
        $this->eventConfig->delete();

        // Create a user-less event with no config
        $event = Event::factory()->create([
            'start_time' => now()->subHour(),
            'end_time' => now()->addDay(),
        ]);

        $this->actingAs($this->user);

        // Force no context event by clearing session
        session()->forget('viewing_event_id');

        Livewire::test(ScheduleTimeline::class)
            ->assertStatus(200);
    });

    test('displays shifts grouped by role', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
            'icon' => 'o-radio',
        ]);

        Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => now()->addHour(),
            'end_time' => now()->addHours(3),
            'capacity' => 3,
            'is_open' => true,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->assertSee('Station Operator')
            ->assertSee('0/3 filled');
    });
});

describe('sign up', function () {
    test('user can sign up for an open shift with capacity', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->open()->withCapacity(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('signUp', $shift->id)
            ->assertDispatched('toast');

        expect(ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->user->id)->exists())->toBeTrue();
    });

    test('user cannot sign up for a closed shift', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->withCapacity(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'is_open' => false,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('signUp', $shift->id)
            ->assertDispatched('toast', title: 'Error');

        expect(ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->user->id)->exists())->toBeFalse();
    });

    test('user cannot sign up for a full shift', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->open()->withCapacity(1)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        // Fill the shift
        ShiftAssignment::factory()->create(['shift_id' => $shift->id]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('signUp', $shift->id)
            ->assertDispatched('toast', title: 'Error');
    });

    test('user cannot sign up twice for the same shift', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->open()->withCapacity(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('signUp', $shift->id)
            ->assertDispatched('toast', title: 'Error');
    });
});

describe('cancel sign up', function () {
    test('user can cancel own self-signup', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->open()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('cancelSignUp', $assignment->id)
            ->assertDispatched('toast', title: 'Success');

        expect(ShiftAssignment::find($assignment->id))->toBeNull();
    });
});

describe('check in and check out', function () {
    test('user can check in to a scheduled shift', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('checkIn', $assignment->id)
            ->assertDispatched('toast', title: 'Success');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
    });

    test('user can check out of a checked-in shift', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('checkOut', $assignment->id)
            ->assertDispatched('toast', title: 'Success');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
    });
});
