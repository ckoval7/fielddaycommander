<?php

use App\Livewire\Schedule\ManageSchedule;
use App\Models\AuditLog;
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
    Permission::create(['name' => 'manage-shifts']);

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
    Setting::set('time_format', 'g:i:s A');

    $this->admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $this->admin->givePermissionTo('manage-shifts');

    $this->regularUser = User::factory()->create([
        'first_name' => 'Regular',
        'last_name' => 'User',
    ]);

    $this->role = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Operator',
        'icon' => 'o-radio',
        'color' => '#6366f1',
        'requires_confirmation' => false,
    ]);
});

// =============================================================================
// Access Control
// =============================================================================

describe('access control', function () {
    test('requires authentication', function () {
        Livewire::test(ManageSchedule::class)
            ->assertForbidden();
    });

    test('requires manage-shifts permission', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(ManageSchedule::class)
            ->assertForbidden();
    });

    test('allows users with manage-shifts permission', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->assertStatus(200)
            ->assertSee('Manage Schedule');
    });
});

// =============================================================================
// Role CRUD
// =============================================================================

describe('role management', function () {
    test('can create a custom role', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openRoleModal')
            ->assertSet('showRoleModal', true)
            ->set('roleName', 'Band Captain')
            ->set('roleDescription', 'Leads a band station')
            ->set('roleIcon', 'o-star')
            ->set('roleColor', '#14b8a6')
            ->call('saveRole')
            ->assertSet('showRoleModal', false)
            ->assertDispatched('toast', title: 'Success', description: 'Role created successfully');

        expect(ShiftRole::where('name', 'Band Captain')->exists())->toBeTrue();
    });

    test('creating role with bonus points forces requires_confirmation', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openRoleModal')
            ->set('roleName', 'Bonus Role')
            ->set('roleBonusPoints', 100)
            ->set('roleRequiresConfirmation', false)
            ->call('saveRole')
            ->assertDispatched('toast', title: 'Success');

        $role = ShiftRole::where('name', 'Bonus Role')->first();
        expect($role->requires_confirmation)->toBeTrue();
        expect($role->bonus_points)->toBe(100);
    });

    test('can edit a role', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openRoleModal', $this->role->id)
            ->assertSet('editingRoleId', $this->role->id)
            ->assertSet('roleName', 'Station Operator')
            ->set('roleName', 'Updated Operator')
            ->call('saveRole')
            ->assertDispatched('toast', title: 'Success', description: 'Role updated successfully');

        expect($this->role->fresh()->name)->toBe('Updated Operator');
    });

    test('can delete a role without assigned shifts', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('deleteRole', $this->role->id)
            ->assertDispatched('toast', title: 'Success', description: 'Role deleted successfully');

        expect($this->role->fresh()->trashed())->toBeTrue();
    });

    test('cannot delete a role with assigned shifts', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('deleteRole', $this->role->id)
            ->assertDispatched('toast', title: 'Error', description: 'Cannot delete role with assigned shifts. Remove assignments first.');

        expect($this->role->fresh()->trashed())->toBeFalse();
    });
});

// =============================================================================
// Shift CRUD
// =============================================================================

describe('shift management', function () {
    test('can create a shift', function () {
        $this->actingAs($this->admin);

        $startTime = appNow()->addHour()->format('Y-m-d\TH:i');
        $endTime = appNow()->addHours(3)->format('Y-m-d\TH:i');

        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal')
            ->assertSet('showShiftModal', true)
            ->set('shiftRoleId', $this->role->id)
            ->set('shiftStartTime', $startTime)
            ->set('shiftEndTime', $endTime)
            ->set('shiftCapacity', 3)
            ->set('shiftIsOpen', true)
            ->call('saveShift')
            ->assertSet('showShiftModal', false)
            ->assertDispatched('toast', title: 'Success', description: 'Shift created successfully');

        expect(Shift::where('shift_role_id', $this->role->id)->count())->toBe(1);
    });

    test('can edit a shift', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'capacity' => 2,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal', $shift->id)
            ->assertSet('editingShiftId', $shift->id)
            ->set('shiftCapacity', 5)
            ->call('saveShift')
            ->assertDispatched('toast', title: 'Success', description: 'Shift updated successfully');

        expect($shift->fresh()->capacity)->toBe(5);
    });

    test('validates end time after start time', function () {
        $this->actingAs($this->admin);

        $time = appNow()->addHour()->format('Y-m-d\TH:i');

        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal')
            ->set('shiftRoleId', $this->role->id)
            ->set('shiftStartTime', $time)
            ->set('shiftEndTime', $time)
            ->set('shiftCapacity', 1)
            ->call('saveShift')
            ->assertHasErrors('shiftEndTime');
    });

    test('can delete a shift', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('deleteShift', $shift->id)
            ->assertDispatched('toast', title: 'Success', description: 'Shift deleted successfully');

        expect($shift->fresh()->trashed())->toBeTrue();
    });

    test('deleting shift also deletes assignments', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('deleteShift', $shift->id)
            ->assertDispatched('toast');

        expect($assignment->fresh()->trashed())->toBeTrue();
    });
});

// =============================================================================
// Bulk Creation
// =============================================================================

describe('bulk creation', function () {
    test('can bulk create shifts', function () {
        $this->actingAs($this->admin);

        $startTime = appNow()->format('Y-m-d\TH:i');
        $endTime = appNow()->addHours(6)->format('Y-m-d\TH:i');

        Livewire::test(ManageSchedule::class)
            ->call('openBulkModal')
            ->assertSet('showBulkModal', true)
            ->set('bulkRoleId', $this->role->id)
            ->set('bulkStartTime', $startTime)
            ->set('bulkEndTime', $endTime)
            ->set('bulkDurationMinutes', 120)
            ->set('bulkCapacity', 2)
            ->call('createBulkShifts')
            ->assertSet('showBulkModal', false)
            ->assertDispatched('toast', title: 'Success', description: '3 shifts created successfully');

        expect(Shift::where('shift_role_id', $this->role->id)->count())->toBe(3);
    });

    test('bulk create adds short shift to fill remaining time', function () {
        $this->actingAs($this->admin);

        $startTime = appNow()->format('Y-m-d\TH:i');
        $endTime = appNow()->addHours(5)->format('Y-m-d\TH:i');

        Livewire::test(ManageSchedule::class)
            ->call('openBulkModal')
            ->set('bulkRoleId', $this->role->id)
            ->set('bulkStartTime', $startTime)
            ->set('bulkEndTime', $endTime)
            ->set('bulkDurationMinutes', 120)
            ->set('bulkCapacity', 1)
            ->call('createBulkShifts')
            ->assertDispatched('toast', title: 'Success', description: '3 shifts created successfully');

        $shifts = Shift::where('shift_role_id', $this->role->id)->orderBy('start_time')->get();
        expect($shifts)->toHaveCount(3);

        // First two shifts are full 2-hour shifts
        expect((int) $shifts[0]->start_time->diffInMinutes($shifts[0]->end_time))->toBe(120);
        expect((int) $shifts[1]->start_time->diffInMinutes($shifts[1]->end_time))->toBe(120);

        // Third shift covers the remaining 1 hour
        expect((int) $shifts[2]->start_time->diffInMinutes($shifts[2]->end_time))->toBe(60);
    });

    test('bulk creation pre-fills event times', function () {
        $this->actingAs($this->admin);

        $component = Livewire::test(ManageSchedule::class)
            ->call('openBulkModal');

        expect($component->get('bulkStartTime'))->not->toBeEmpty();
        expect($component->get('bulkEndTime'))->not->toBeEmpty();
    });
});

// =============================================================================
// Assignments
// =============================================================================

describe('assignments', function () {
    test('can assign a user to a shift', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'capacity' => 2,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openAssignModal', $shift->id)
            ->assertSet('showAssignModal', true)
            ->set('assignUserId', $this->regularUser->id)
            ->call('assignUser')
            ->assertSet('showAssignModal', false)
            ->assertDispatched('toast', title: 'Success', description: 'User assigned to shift');

        expect(ShiftAssignment::where('shift_id', $shift->id)
            ->where('user_id', $this->regularUser->id)->exists())->toBeTrue();
    });

    test('cannot assign duplicate user to same shift', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'capacity' => 2,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openAssignModal', $shift->id)
            ->set('assignUserId', $this->regularUser->id)
            ->call('assignUser')
            ->assertDispatched('toast', title: 'Error', description: 'User is already assigned to this shift.');
    });

    test('cannot assign user when shift at capacity', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'capacity' => 1,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openAssignModal', $shift->id)
            ->set('assignUserId', $this->regularUser->id)
            ->call('assignUser')
            ->assertDispatched('toast', title: 'Error', description: 'This shift is already at capacity.');
    });

    test('can remove an assignment', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('removeAssignment', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'Assignment removed');

        expect($assignment->fresh()->trashed())->toBeTrue();
    });
});

// =============================================================================
// Confirmations
// =============================================================================

describe('confirmations', function () {
    test('can confirm a check-in', function () {
        $bonusRole = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
            'requires_confirmation' => true,
            'bonus_points' => 100,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $bonusRole->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => appNow(),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('confirmCheckIn', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'Check-in confirmed');

        $assignment->refresh();
        expect($assignment->confirmed_by_user_id)->toBe($this->admin->id);
        expect($assignment->confirmed_at)->not->toBeNull();
    });

    test('can revoke a confirmation', function () {
        $bonusRole = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
            'requires_confirmation' => true,
            'bonus_points' => 100,
        ]);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $bonusRole->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => appNow(),
            'confirmed_by_user_id' => $this->admin->id,
            'confirmed_at' => appNow(),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('revokeConfirmation', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'Confirmation revoked');

        $assignment->refresh();
        expect($assignment->confirmed_by_user_id)->toBeNull();
        expect($assignment->confirmed_at)->toBeNull();
    });
});

// =============================================================================
// Manager Overrides
// =============================================================================

describe('manager overrides', function () {
    test('can manager check in a user', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('managerCheckIn', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'User checked in');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
    });

    test('can manager check out a user', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => appNow()->subMinutes(30),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('managerCheckOut', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'User checked out');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
    });

    test('can mark a user as no-show', function () {
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('markNoShow', $assignment->id)
            ->assertDispatched('toast', title: 'Success', description: 'Marked as no-show');

        expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_NO_SHOW);
    });
});

// =============================================================================
// Filtering & Sorting
// =============================================================================

describe('filtering and sorting', function () {
    test('can filter shifts by role', function () {
        $this->actingAs($this->admin);

        $role2 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
        ]);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('role', (string) $role2->id)
            ->assertSee(toLocalTime($shift2->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($shift1->start_time)->format('M j, '.timeFormat()));
    });

    test('can search shifts by assigned user name', function () {
        $this->actingAs($this->admin);

        $user1 = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith', 'call_sign' => 'W1ABC']);
        $user2 = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Jones', 'call_sign' => 'W2XYZ']);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shift1->id, 'user_id' => $user1->id]);
        ShiftAssignment::factory()->create(['shift_id' => $shift2->id, 'user_id' => $user2->id]);

        Livewire::test(ManageSchedule::class)
            ->set('search', 'Alice')
            ->assertSee('Alice')
            ->assertDontSee('Bob');
    });

    test('can search shifts by call sign', function () {
        $this->actingAs($this->admin);

        $user1 = User::factory()->create(['first_name' => 'Alice', 'call_sign' => 'W1ABC']);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shift1->id, 'user_id' => $user1->id]);

        Livewire::test(ManageSchedule::class)
            ->set('search', 'W1ABC')
            ->assertSee('Alice');
    });

    test('can filter unfilled shifts', function () {
        $this->actingAs($this->admin);

        $fullShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
            'capacity' => 1,
        ]);
        ShiftAssignment::factory()->create(['shift_id' => $fullShift->id, 'user_id' => $this->regularUser->id]);

        $openShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
            'capacity' => 3,
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('availability', 'unfilled')
            ->assertSee(toLocalTime($openShift->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($fullShift->start_time)->format('M j, '.timeFormat()));
    });

    test('can filter shifts by time period', function () {
        $this->actingAs($this->admin);

        $pastShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->subHours(4),
            'end_time' => appNow()->subHours(2),
        ]);

        $upcomingShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('timeFilter', 'upcoming')
            ->assertSee(toLocalTime($upcomingShift->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($pastShift->start_time)->format('M j, '.timeFormat()));
    });

    test('can filter shifts by assignment status', function () {
        $this->actingAs($this->admin);

        $shift1 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        $shift2 = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift1->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift2->id,
            'user_id' => $this->admin->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('status', 'checked_in')
            ->assertSee(toLocalTime($shift1->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($shift2->start_time)->format('M j, '.timeFormat()));
    });

    test('multiple filters compose with AND logic', function () {
        $this->actingAs($this->admin);

        $role2 = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
        ]);

        $matchingShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
            'capacity' => 3,
        ]);

        $fullShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $role2->id,
            'start_time' => appNow()->addHours(4),
            'end_time' => appNow()->addHours(6),
            'capacity' => 1,
        ]);
        ShiftAssignment::factory()->create(['shift_id' => $fullShift->id, 'user_id' => $this->regularUser->id]);

        $wrongRoleShift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(7),
            'end_time' => appNow()->addHours(9),
            'capacity' => 3,
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('role', (string) $role2->id)
            ->set('availability', 'unfilled')
            ->assertSee(toLocalTime($matchingShift->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($fullShift->start_time)->format('M j, '.timeFormat()))
            ->assertDontSee(toLocalTime($wrongRoleShift->start_time)->format('M j, '.timeFormat()));
    });

    test('can reset all filters', function () {
        $this->actingAs($this->admin);

        Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('role', (string) $this->role->id)
            ->set('timeFilter', 'past')
            ->call('resetFilters')
            ->assertSet('role', '')
            ->assertSet('timeFilter', '')
            ->assertSet('search', '')
            ->assertSet('availability', '');
    });

    test('can sort shifts by time descending', function () {
        $this->actingAs($this->admin);

        $early = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        $late = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHours(5),
            'end_time' => appNow()->addHours(7),
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('sortBy', 'time')
            ->set('sortDir', 'desc')
            ->assertSeeInOrder([
                toLocalTime($late->start_time)->format('M j, '.timeFormat()),
                toLocalTime($early->start_time)->format('M j, '.timeFormat()),
            ]);
    });

    test('shows empty state when filters match nothing', function () {
        $this->actingAs($this->admin);

        Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);

        Livewire::test(ManageSchedule::class)
            ->set('timeFilter', 'past')
            ->assertSee('No shifts match your filters');
    });
});

// =============================================================================
// Audit Logging
// =============================================================================

describe('audit logging', function () {
    test('assigning a user to a shift logs to audit log', function () {
        $this->actingAs($this->admin);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'capacity' => 3,
        ]);

        Livewire::test(ManageSchedule::class)
            ->call('openAssignModal', $shift->id)
            ->set('assignUserId', $this->regularUser->id)
            ->call('assignUser');

        $assignment = ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->regularUser->id)->first();

        $auditLog = AuditLog::where('action', 'shift.assigned')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->admin->id);
        expect($auditLog->auditable_type)->toBe(ShiftAssignment::class);
        expect($auditLog->new_values['assigned_user'])->toBe($this->regularUser->call_sign);
        expect($auditLog->new_values['role'])->toBe('Station Operator');
    });

    test('removing an assignment logs to audit log', function () {
        $this->actingAs($this->admin);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ]);

        Livewire::test(ManageSchedule::class)
            ->call('removeAssignment', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.removed')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values['removed_user'])->toBe($this->regularUser->call_sign);
    });

    test('manager check-in logs to audit log', function () {
        $this->actingAs($this->admin);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ]);

        Livewire::test(ManageSchedule::class)
            ->call('managerCheckIn', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.manager_checkin')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->new_values['status'])->toBe('checked_in');
        expect($auditLog->new_values['managed_by'])->toBe($this->admin->call_sign);
    });

    test('marking no-show logs to audit log', function () {
        $this->actingAs($this->admin);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $this->regularUser->id,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ]);

        Livewire::test(ManageSchedule::class)
            ->call('markNoShow', $assignment->id);

        $auditLog = AuditLog::where('action', 'shift.no_show')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->new_values['user'])->toBe($this->regularUser->call_sign);
    });

    test('creating a shift role logs to audit log', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openRoleModal')
            ->set('roleName', 'Safety Officer')
            ->set('roleDescription', 'Oversees safety')
            ->call('saveRole');

        $role = ShiftRole::where('name', 'Safety Officer')->first();

        $auditLog = AuditLog::where('action', 'shift.role.created')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->auditable_type)->toBe(ShiftRole::class);
        expect($auditLog->auditable_id)->toBe($role->id);
        expect($auditLog->new_values['name'])->toBe('Safety Officer');
    });

    test('creating a shift logs to audit log', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal')
            ->set('shiftRoleId', $this->role->id)
            ->set('shiftStartTime', appNow()->format('Y-m-d\TH:i'))
            ->set('shiftEndTime', appNow()->addHours(2)->format('Y-m-d\TH:i'))
            ->set('shiftCapacity', 3)
            ->call('saveShift');

        $auditLog = AuditLog::where('action', 'shift.created')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->new_values['role'])->toBe('Station Operator');
        expect($auditLog->new_values['capacity'])->toBe(3);
    });

    test('deleting a shift logs to audit log', function () {
        $this->actingAs($this->admin);

        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
        ]);

        Livewire::test(ManageSchedule::class)
            ->call('deleteShift', $shift->id);

        $auditLog = AuditLog::where('action', 'shift.deleted')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values['role'])->toBe('Station Operator');
    });
});

// =============================================================================
// Timezone Handling
// =============================================================================

describe('timezone handling', function () {
    test('saves shift times as UTC when system timezone is non-UTC', function () {
        Setting::set('timezone', 'America/New_York');
        $this->actingAs($this->admin);

        // 10:00 AM Eastern Daylight Time = 14:00 UTC
        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal')
            ->set('shiftRoleId', $this->role->id)
            ->set('shiftStartTime', '2026-06-28T10:00')
            ->set('shiftEndTime', '2026-06-28T12:00')
            ->set('shiftCapacity', 1)
            ->call('saveShift')
            ->assertDispatched('toast', title: 'Success');

        $shift = Shift::where('shift_role_id', $this->role->id)->first();
        expect($shift->start_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-28 14:00:00');
        expect($shift->end_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-28 16:00:00');
    });

    test('populates shift edit form with times in local timezone', function () {
        Setting::set('timezone', 'America/New_York');

        // Shift stored at 14:00 UTC = 10:00 AM EDT
        $shift = Shift::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'shift_role_id' => $this->role->id,
            'start_time' => '2026-06-28 14:00:00',
            'end_time' => '2026-06-28 16:00:00',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSchedule::class)
            ->call('openShiftModal', $shift->id)
            ->assertSet('shiftStartTime', '2026-06-28T10:00')
            ->assertSet('shiftEndTime', '2026-06-28T12:00');
    });

    test('bulk create saves shifts as UTC when system timezone is non-UTC', function () {
        Setting::set('timezone', 'America/New_York');
        $this->actingAs($this->admin);

        // 10:00 AM to 12:00 PM Eastern = 14:00 to 16:00 UTC
        Livewire::test(ManageSchedule::class)
            ->call('openBulkModal')
            ->set('bulkRoleId', $this->role->id)
            ->set('bulkStartTime', '2026-06-28T10:00')
            ->set('bulkEndTime', '2026-06-28T12:00')
            ->set('bulkDurationMinutes', 120)
            ->set('bulkCapacity', 1)
            ->call('createBulkShifts')
            ->assertDispatched('toast', title: 'Success');

        $shift = Shift::where('shift_role_id', $this->role->id)->first();
        expect($shift->start_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-28 14:00:00');
        expect($shift->end_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-06-28 16:00:00');
    });

    test('bulk modal pre-fills event times in local timezone', function () {
        Setting::set('timezone', 'America/New_York');
        $this->actingAs($this->admin);

        // The pre-fill should use the local timezone, not raw UTC
        $expectedStart = toLocalTime($this->event->start_time)->format('Y-m-d\TH:i');
        $expectedEnd = toLocalTime($this->event->end_time)->format('Y-m-d\TH:i');

        Livewire::test(ManageSchedule::class)
            ->call('openBulkModal')
            ->assertSet('bulkStartTime', $expectedStart)
            ->assertSet('bulkEndTime', $expectedEnd);
    });
});
