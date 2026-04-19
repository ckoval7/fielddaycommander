<?php

use App\Livewire\Schedule\MyShifts;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Services\EventContextService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->user = User::factory()->create();

    Permission::firstOrCreate(['name' => 'sign-up-shifts']);
    $this->user->givePermissionTo('sign-up-shifts');

    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->role = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Operator',
        'icon' => 'phosphor-radio',
        'color' => '#6366f1',
        'requires_confirmation' => false,
    ]);
});

test('my shifts page renders successfully', function () {
    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertStatus(200)
        ->assertSee('My Shifts');
});

test('my shifts shows empty state when no shifts', function () {
    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('You have no shifts happening right now.')
        ->assertSee('You have no upcoming shifts scheduled.')
        ->assertSee('You have no past shifts for this event.');
});

test('my shifts shows current shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Station Operator');
});

test('my shifts shows upcoming shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(4),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Station Operator');
});

test('my shifts shows past shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(2),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Checked Out');
});

test('user can check in to a scheduled shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('checkIn', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'You have checked in.');

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
});

test('user can check out of a checked-in shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => appNow()->subMinutes(30),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('checkOut', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'You have checked out.');

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
});

test('checkout confirmation warning is shown when shift has not ended', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => appNow()->subMinutes(30),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Are you sure you want to check out?', false);
});

test('checkout confirmation warning is not shown when shift has already ended', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(3),
        'end_time' => appNow()->subHour(),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => appNow()->subHours(2),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertDontSee('Are you sure you want to check out?', false);
});

test('user can drop a self-signup', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(4),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('cancelSignUp', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'Shift has been dropped.');

    expect($assignment->fresh()->trashed())->toBeTrue();
});

test('user cannot drop an assigned shift', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(4),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
    ]);

    $this->actingAs($this->user);

    expect(fn () => Livewire::test(MyShifts::class)
        ->call('cancelSignUp', $assignment->id)
    )->toThrow(ModelNotFoundException::class);
});

test('user cannot check in to another users shift', function () {
    $otherUser = User::factory()->create();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $otherUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    expect(fn () => Livewire::test(MyShifts::class)
        ->call('checkIn', $assignment->id)
    )->toThrow(ModelNotFoundException::class);
});

test('my shifts shows confirmation badge for bonus roles', function () {
    $bonusRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Safety Officer',
        'icon' => 'phosphor-shield-check',
        'color' => '#f59e0b',
        'requires_confirmation' => true,
        'bonus_points' => 100,
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $bonusRole->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => appNow()->subMinutes(30),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Pending Confirmation');
});

test('my shifts shows no event alert when no event configured', function () {
    // Remove all events so none can be found
    Event::query()->forceDelete();
    Setting::set('active_event_id', null);
    app(EventContextService::class)->clearCache();

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('No event is currently selected');
});

// =============================================================================
// Filtering
// =============================================================================

describe('filtering', function () {
    test('can filter shifts by role', function () {
        $role2 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
            'icon' => 'phosphor-shield-check',
            'color' => '#ef4444',
        ]);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHours(5),
            'end_time' => appNow()->addHours(7),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shift1->id, 'user_id' => $this->user->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shift2->id, 'user_id' => $this->user->id]);

        Setting::set('time_format', 'h:i:s A');
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('role', (string) $role2->id)
            ->assertSee(toLocalTime($shift2->start_time)->format('g:i A'))
            ->assertDontSee(toLocalTime($shift1->start_time)->format('g:i A'));
    });

    test('can filter by assignment status', function () {
        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->subHours(4),
            'end_time' => appNow()->subHours(2),
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift1->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift2->id,
            'user_id' => $this->user->id,
            'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        ]);

        Setting::set('time_format', 'h:i:s A');
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('status', 'checked_out')
            ->assertSee('Checked Out')
            ->assertDontSee(toLocalTime($shift1->start_time)->format('g:i A'));
    });

    test('sort by time ascending shows earlier shift first', function () {
        $shiftEarly = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $shiftLate = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(6),
            'end_time' => appNow()->addHours(8),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shiftEarly->id, 'user_id' => $this->user->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shiftLate->id, 'user_id' => $this->user->id]);

        Setting::set('time_format', 'h:i:s A');
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('sortBy', 'time')
            ->set('sortDir', 'asc')
            ->assertSeeInOrder([
                toLocalTime($shiftEarly->start_time)->format('g:i A'),
                toLocalTime($shiftLate->start_time)->format('g:i A'),
            ]);
    });

    test('sort by time descending shows later shift first', function () {
        $shiftEarly = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $shiftLate = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(6),
            'end_time' => appNow()->addHours(8),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shiftEarly->id, 'user_id' => $this->user->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shiftLate->id, 'user_id' => $this->user->id]);

        Setting::set('time_format', 'h:i:s A');
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('sortBy', 'time')
            ->set('sortDir', 'desc')
            ->assertSeeInOrder([
                toLocalTime($shiftLate->start_time)->format('g:i A'),
                toLocalTime($shiftEarly->start_time)->format('g:i A'),
            ]);
    });

    test('sort by role orders by role sort_order', function () {
        $role1 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Alpha Role',
            'sort_order' => 0,
        ]);

        $role2 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Beta Role',
            'sort_order' => 1,
        ]);

        $shiftForRole2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $shiftForRole1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role1->id,
            'start_time' => appNow()->addHours(3),
            'end_time' => appNow()->addHours(5),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shiftForRole2->id, 'user_id' => $this->user->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shiftForRole1->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('sortBy', 'role')
            ->set('sortDir', 'asc')
            ->assertSeeInOrder(['Alpha Role', 'Beta Role']);
    });

    test('can reset filters', function () {
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->set('role', (string) $this->role->id)
            ->set('status', 'checked_in')
            ->call('resetFilters')
            ->assertSet('role', '')
            ->assertSet('status', '');
    });

    test('search filter is not available on my shifts', function () {
        $this->actingAs($this->user);

        Livewire::test(MyShifts::class)
            ->assertDontSee('Search by name');
    });
});

test('my shifts does not show other users shifts', function () {
    $otherUser = User::factory()->create();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(4),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $otherUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('You have no upcoming shifts scheduled.');
});

test('user can check out of a past shift they forgot to check out of', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(2),
    ]);

    $assignment = ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => appNow()->subHours(4),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee('Check Out')
        ->call('checkOut', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'You have checked out.');

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
});

test('user can re-check-in to a checked-out shift while still active', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
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

    Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'You have checked back in.');

    $assignment->refresh();
    expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
        ->and($assignment->checked_out_at)->toBeNull()
        ->and($assignment->checked_in_at->timestamp)->toBe($checkedInAt->timestamp);
});

test('user cannot re-check-in after shift has ended', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(1),
    ]);

    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
        ->assertDispatched('toast', title: 'Too Late');

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
});

test('shift times display in 24-hour format when configured', function () {
    Setting::set('time_format', 'H:i:s');

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2)->setTime(14, 30),
        'end_time' => appNow()->addHours(2)->setTime(16, 0),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee(toLocalTime($shift->start_time)->format('H:i'))
        ->assertDontSee(toLocalTime($shift->start_time)->format('g:i A'));
});

test('shift times display in 12-hour format when configured', function () {
    Setting::set('time_format', 'h:i:s A');

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2)->setTime(14, 30),
        'end_time' => appNow()->addHours(2)->setTime(16, 0),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSee(toLocalTime($shift->start_time)->format('g:i A'))
        ->assertDontSee(toLocalTime($shift->start_time)->format('H:i').' ');
});

test('user cannot re-check-in to another users assignment', function () {
    $otherUser = User::factory()->create();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'shift_id' => $shift->id,
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($this->user);

    expect(fn () => Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
    )->toThrow(ModelNotFoundException::class);
});

test('my shifts summary totals hours worked and signed up for the event', function () {
    // Past shift fully worked (2 hours)
    $pastShift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(5),
        'end_time' => appNow()->subHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $pastShift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $pastShift->start_time,
        'checked_out_at' => $pastShift->end_time,
    ]);

    // Upcoming shift (3 hours scheduled, 0 worked)
    $upcomingShift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHours(2),
        'end_time' => appNow()->addHours(5),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $upcomingShift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test(MyShifts::class);

    expect($component->instance()->hoursWorkedThisEvent())->toBe(2.0);
    expect($component->instance()->hoursSignedUpThisEvent())->toBe(5.0);
});

test('my shifts summary excludes no-show assignments from signed-up hours', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(3),
        'end_time' => appNow()->subHours(1),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_NO_SHOW,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test(MyShifts::class);

    expect($component->instance()->hoursWorkedThisEvent())->toBe(0.0);
    expect($component->instance()->hoursSignedUpThisEvent())->toBe(0.0);
});

test('my shifts summary caps worked hours at scheduled length', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(2),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $shift->start_time,
        'checked_out_at' => $shift->end_time->copy()->addHours(1),
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->assertSeeHtml('2.0 hours worked')
        ->assertSeeHtml('2.0 hours signed up');
});
