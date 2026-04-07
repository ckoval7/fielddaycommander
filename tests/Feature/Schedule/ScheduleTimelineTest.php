<?php

use App\Livewire\Schedule\ScheduleTimeline;
use App\Models\AuditLog;
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
            'start_time' => appNow()->subMinutes(10),
            'end_time' => appNow()->addHours(2),
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

describe('re-check-in', function () {
    test('user can re-check-in to a checked-out shift while still active', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHour(),
        ]);

        $checkedInAt = appNow()->subHour();
        $assignment = ShiftAssignment::factory()->checkedOut()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'checked_in_at' => $checkedInAt,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('reCheckIn', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'You have checked back in.');

        $assignment->refresh();
        expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
            ->and($assignment->checked_out_at)->toBeNull()
            ->and($assignment->checked_in_at->timestamp)->toBe($checkedInAt->timestamp);
    });

    test('user cannot re-check-in after shift has ended', function () {
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->subHours(4),
            'end_time' => appNow()->subHours(1),
        ]);

        $assignment = ShiftAssignment::factory()->checkedOut()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->call('reCheckIn', $assignment->id)
            ->assertDispatched('toast', title: 'Too Late');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
    });

    test('user cannot re-check-in to another users assignment', function () {
        $otherUser = User::factory()->create();

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHour(),
        ]);

        $assignment = ShiftAssignment::factory()->checkedOut()->create([
            'shift_id' => $shift->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($this->user);

        expect(fn () => Livewire::test(ScheduleTimeline::class)
            ->call('reCheckIn', $assignment->id)
        )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });
});

// =============================================================================
// Filtering & Sorting
// =============================================================================

describe('filtering and sorting', function () {
    test('can filter by role', function () {
        $this->actingAs($this->user);

        $role1 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
            'icon' => 'o-radio',
        ]);

        $role2 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
            'icon' => 'o-shield-check',
        ]);

        Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role1->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $component = Livewire::test(ScheduleTimeline::class)
            ->set('role', (string) $role1->id)
            ->assertSee('Station Operator');

        // Verify filtered shifts only contain role1 (role names also appear in filter dropdown <template> tag)
        $filteredShifts = $component->instance()->filteredShifts;
        expect($filteredShifts)->toHaveCount(1);
        expect($filteredShifts->first()->shift_role_id)->toBe($role1->id);
    });

    test('can search by assigned user name', function () {
        $this->actingAs($this->user);

        $user1 = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
        $user2 = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Jones']);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Operator',
        ]);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shift1->id, 'user_id' => $user1->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shift2->id, 'user_id' => $user2->id]);

        Livewire::test(ScheduleTimeline::class)
            ->set('search', 'Alice')
            ->assertSee('Alice')
            ->assertDontSee('Bob');
    });

    test('can filter unfilled shifts only', function () {
        $this->actingAs($this->user);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Operator',
        ]);

        $fullShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
            'capacity' => 1,
            'is_open' => true,
        ]);
        ShiftAssignment::factory()->create(['shift_id' => $fullShift->id, 'user_id' => $this->user->id]);

        $openShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
            'capacity' => 3,
            'is_open' => true,
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->set('availability', 'unfilled')
            ->assertSee($openShift->start_time->format('M j, g:i A'))
            ->assertDontSee($fullShift->start_time->format('M j, g:i A'));
    });

    test('shows empty state when filters match nothing', function () {
        $this->actingAs($this->user);

        ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Operator',
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->set('timeFilter', 'current')
            ->assertSee('No shifts match your filters');
    });

    test('can reset filters', function () {
        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->set('role', '1')
            ->set('timeFilter', 'upcoming')
            ->call('resetFilters')
            ->assertSet('role', '')
            ->assertSet('timeFilter', '');
    });
});

describe('audit logging', function () {
    test('signing up for a shift logs to audit log', function () {
        $this->actingAs($this->user);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'is_open' => true,
            'capacity' => 3,
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->call('signUp', $shift->id);

        $assignment = ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->user->id)->first();

        $auditLog = AuditLog::where('action', 'shift.signup')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->user->id);
        expect($auditLog->auditable_type)->toBe(ShiftAssignment::class);
        expect($auditLog->auditable_id)->toBe($assignment->id);
        expect($auditLog->new_values['role'])->toBe('Station Operator');
    });

    test('cancelling a signup logs to audit log', function () {
        $this->actingAs($this->user);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'is_open' => true,
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->call('cancelSignUp', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.signup.cancelled')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values['role'])->toBe('Station Operator');
    });

    test('checking in logs to audit log', function () {
        $this->actingAs($this->user);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->subMinutes(5),
            'end_time' => appNow()->addHours(2),
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->call('checkIn', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.checkin')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->new_values['status'])->toBe('checked_in');
    });

    test('checking out logs to audit log', function () {
        $this->actingAs($this->user);

        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Operator',
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role->id,
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        Livewire::test(ScheduleTimeline::class)
            ->call('checkOut', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.checkout')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->new_values['status'])->toBe('checked_out');
    });
});
