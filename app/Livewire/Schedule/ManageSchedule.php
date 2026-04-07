<?php

namespace App\Livewire\Schedule;

use App\Livewire\Schedule\Concerns\WithScheduleFilters;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Services\EventContextService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManageSchedule extends Component
{
    use WithScheduleFilters;

    private const DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const DEFAULT_ROLE_COLOR = '#64748b';

    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    // Tab state
    public string $activeTab = 'shifts';

    // Role modal
    public bool $showRoleModal = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    public string $roleDescription = '';

    public ?int $roleBonusPoints = null;

    public bool $roleRequiresConfirmation = false;

    public string $roleIcon = 'o-user-group';

    public string $roleColor = self::DEFAULT_ROLE_COLOR;

    // Shift modal
    public bool $showShiftModal = false;

    public ?int $editingShiftId = null;

    public ?int $shiftRoleId = null;

    public string $shiftStartTime = '';

    public string $shiftEndTime = '';

    public int $shiftCapacity = 1;

    public bool $shiftIsOpen = true;

    public string $shiftNotes = '';

    // Bulk creation modal
    public bool $showBulkModal = false;

    public ?int $bulkRoleId = null;

    public string $bulkStartTime = '';

    public string $bulkEndTime = '';

    public int $bulkDurationMinutes = 120;

    public int $bulkCapacity = 1;

    public bool $bulkIsOpen = true;

    // Assignment modal
    public bool $showAssignModal = false;

    public ?int $assignShiftId = null;

    public ?int $assignUserId = null;

    public function mount(): void
    {
        Gate::authorize('manage-shifts');

        $contextService = app(EventContextService::class);
        $this->event = $contextService->getContextEvent();

        // Fall back to current event for pre-event planning
        if (! $this->event) {
            $this->event = Event::where('is_current', true)->first()
                ?? Event::upcoming()->orderBy('start_time', 'asc')->first();
        }

        $this->eventConfig = $this->event?->eventConfiguration;

        // Seed default roles if none exist
        if ($this->eventConfig && $this->eventConfig->shiftRoles()->count() === 0) {
            ShiftRole::seedDefaults($this->eventConfig);
        }
    }

    /**
     * @return Collection<int, ShiftRole>
     */
    #[Computed]
    public function roles(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return ShiftRole::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->get();
    }

    /**
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        $query = Shift::query()
            ->forEvent($this->eventConfig->id)
            ->with(['shiftRole', 'assignments.user']);

        $this->applyShiftFilters($query);
        $this->applyShiftSorting($query);

        return $query->get();
    }

    /**
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function pendingConfirmations(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return ShiftAssignment::query()
            ->pendingConfirmation()
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id);
            })
            ->whereHas('shift.shiftRole', function ($query) {
                $query->where('requires_confirmation', true);
            })
            ->with(['shift.shiftRole', 'user'])
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        if (! $this->showAssignModal) {
            return collect();
        }

        return User::query()
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name)
                    .($user->call_sign ? " ({$user->call_sign})" : ''),
            ]);
    }

    // --- Role CRUD ---

    public function openRoleModal(?int $roleId = null): void
    {
        $this->resetRoleForm();

        if ($roleId) {
            $role = ShiftRole::findOrFail($roleId);
            $this->editingRoleId = $role->id;
            $this->roleName = $role->name;
            $this->roleDescription = $role->description ?? '';
            $this->roleBonusPoints = $role->bonus_points;
            $this->roleRequiresConfirmation = $role->requires_confirmation;
            $this->roleIcon = $role->icon ?? 'o-user-group';
            $this->roleColor = $role->color ?? self::DEFAULT_ROLE_COLOR;
        }

        $this->showRoleModal = true;
    }

    public function saveRole(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'roleName' => ['required', 'string', 'max:255'],
            'roleDescription' => ['nullable', 'string', 'max:1000'],
            'roleBonusPoints' => ['nullable', 'integer', 'min:0'],
            'roleRequiresConfirmation' => ['boolean'],
            'roleIcon' => ['required', 'string', 'max:255'],
            'roleColor' => ['required', 'string', 'max:255'],
        ]);

        // If bonus points are set, force requires_confirmation
        $requiresConfirmation = $this->roleRequiresConfirmation;
        if ($this->roleBonusPoints !== null && $this->roleBonusPoints > 0) {
            $requiresConfirmation = true;
        }

        $data = [
            'name' => $this->roleName,
            'description' => $this->roleDescription ?: null,
            'bonus_points' => $this->roleBonusPoints,
            'requires_confirmation' => $requiresConfirmation,
            'icon' => $this->roleIcon,
            'color' => $this->roleColor,
        ];

        if ($this->editingRoleId) {
            $role = ShiftRole::findOrFail($this->editingRoleId);

            $oldRoleValues = [
                'name' => $role->name,
                'bonus_points' => $role->bonus_points,
                'requires_confirmation' => $role->requires_confirmation,
            ];

            $role->update($data);

            $newRoleValues = array_filter([
                'name' => $role->name,
                'bonus_points' => $role->bonus_points,
                'requires_confirmation' => $role->requires_confirmation,
            ], fn ($value, $key) => $value !== $oldRoleValues[$key], ARRAY_FILTER_USE_BOTH);

            $oldRoleValues = array_intersect_key($oldRoleValues, $newRoleValues);

            if (! empty($newRoleValues)) {
                AuditLog::log(
                    action: 'shift.role.updated',
                    auditable: $role,
                    oldValues: $oldRoleValues,
                    newValues: $newRoleValues,
                );
            }

            $message = 'Role updated successfully';
        } else {
            $maxSortOrder = ShiftRole::query()
                ->forEvent($this->eventConfig->id)
                ->max('sort_order') ?? -1;

            $role = ShiftRole::create(array_merge($data, [
                'event_configuration_id' => $this->eventConfig->id,
                'is_default' => false,
                'sort_order' => $maxSortOrder + 1,
            ]));

            AuditLog::log(
                action: 'shift.role.created',
                auditable: $role,
                newValues: [
                    'name' => $role->name,
                    'bonus_points' => $role->bonus_points,
                    'requires_confirmation' => $role->requires_confirmation,
                ]
            );

            $message = 'Role created successfully';
        }

        $this->showRoleModal = false;
        $this->resetRoleForm();
        unset($this->roles);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'o-check-circle', css: 'alert-success');
    }

    public function deleteRole(int $roleId): void
    {
        Gate::authorize('manage-shifts');

        $role = ShiftRole::findOrFail($roleId);

        // Check if any shifts with assignments exist for this role
        $shiftsWithAssignments = $role->shifts()
            ->whereHas('assignments')
            ->count();

        if ($shiftsWithAssignments > 0) {
            $this->dispatch('toast', title: 'Error', description: 'Cannot delete role with assigned shifts. Remove assignments first.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        // Delete any shifts without assignments for this role
        $role->shifts()->delete();

        AuditLog::log(
            action: 'shift.role.deleted',
            auditable: $role,
            oldValues: [
                'name' => $role->name,
            ]
        );

        $role->delete();

        unset($this->roles);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Role deleted successfully', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Shift CRUD ---

    public function openShiftModal(?int $shiftId = null): void
    {
        $this->resetShiftForm();

        if ($shiftId) {
            $shift = Shift::findOrFail($shiftId);
            $this->editingShiftId = $shift->id;
            $this->shiftRoleId = $shift->shift_role_id;
            $this->shiftStartTime = $shift->start_time->format(self::DATETIME_LOCAL_FORMAT);
            $this->shiftEndTime = $shift->end_time->format(self::DATETIME_LOCAL_FORMAT);
            $this->shiftCapacity = $shift->capacity;
            $this->shiftIsOpen = $shift->is_open;
            $this->shiftNotes = $shift->notes ?? '';
        }

        $this->showShiftModal = true;
    }

    public function saveShift(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'shiftRoleId' => ['required', 'exists:shift_roles,id'],
            'shiftStartTime' => ['required', 'date'],
            'shiftEndTime' => ['required', 'date', 'after:shiftStartTime'],
            'shiftCapacity' => ['required', 'integer', 'min:1'],
            'shiftIsOpen' => ['boolean'],
            'shiftNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'shiftEndTime.after' => 'End time must be after start time.',
        ]);

        $data = [
            'shift_role_id' => $this->shiftRoleId,
            'start_time' => Carbon::parse($this->shiftStartTime),
            'end_time' => Carbon::parse($this->shiftEndTime),
            'capacity' => $this->shiftCapacity,
            'is_open' => $this->shiftIsOpen,
            'notes' => $this->shiftNotes ?: null,
        ];

        if ($this->editingShiftId) {
            $shift = Shift::findOrFail($this->editingShiftId);

            $oldShiftValues = [
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
                'capacity' => $shift->capacity,
                'is_open' => $shift->is_open,
            ];

            $shift->update($data);
            $shift->load('shiftRole');

            $newShiftValues = [
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
                'capacity' => $shift->capacity,
                'is_open' => $shift->is_open,
            ];

            $changedValues = array_filter($newShiftValues, fn ($value, $key) => $value !== $oldShiftValues[$key], ARRAY_FILTER_USE_BOTH);
            $oldChanged = array_intersect_key($oldShiftValues, $changedValues);

            if (! empty($changedValues)) {
                AuditLog::log(
                    action: 'shift.updated',
                    auditable: $shift,
                    oldValues: $oldChanged,
                    newValues: $changedValues,
                );
            }

            $message = 'Shift updated successfully';
        } else {
            $shift = Shift::create(array_merge($data, [
                'event_configuration_id' => $this->eventConfig->id,
            ]));

            AuditLog::log(
                action: 'shift.created',
                auditable: $shift,
                newValues: [
                    'role' => ShiftRole::find($this->shiftRoleId)->name,
                    'start_time' => $shift->start_time->toIso8601String(),
                    'end_time' => $shift->end_time->toIso8601String(),
                    'capacity' => $shift->capacity,
                ]
            );

            $message = 'Shift created successfully';
        }

        $this->showShiftModal = false;
        $this->resetShiftForm();
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'o-check-circle', css: 'alert-success');
    }

    public function deleteShift(int $shiftId): void
    {
        Gate::authorize('manage-shifts');

        $shift = Shift::findOrFail($shiftId);
        $shift->load('shiftRole');
        $assignmentCount = $shift->assignments()->count();

        AuditLog::log(
            action: 'shift.deleted',
            auditable: $shift,
            oldValues: [
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
                'assignments_removed' => $assignmentCount,
            ]
        );

        if ($assignmentCount > 0) {
            $shift->assignments()->delete();
        }

        $shift->delete();

        unset($this->shifts);

        $description = $assignmentCount > 0
            ? "Shift and {$assignmentCount} assignment(s) deleted"
            : 'Shift deleted successfully';

        $this->dispatch('toast', title: 'Success', description: $description, icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Bulk Creation ---

    public function openBulkModal(): void
    {
        $this->resetBulkForm();

        // Pre-fill with event start/end times
        if ($this->event) {
            $this->bulkStartTime = $this->event->start_time?->format(self::DATETIME_LOCAL_FORMAT) ?? '';
            $this->bulkEndTime = $this->event->end_time?->format(self::DATETIME_LOCAL_FORMAT) ?? '';
        }

        $this->showBulkModal = true;
    }

    public function createBulkShifts(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'bulkRoleId' => ['required', 'exists:shift_roles,id'],
            'bulkStartTime' => ['required', 'date'],
            'bulkEndTime' => ['required', 'date', 'after:bulkStartTime'],
            'bulkDurationMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'bulkCapacity' => ['required', 'integer', 'min:1'],
            'bulkIsOpen' => ['boolean'],
        ], [
            'bulkEndTime.after' => 'End time must be after start time.',
            'bulkDurationMinutes.min' => 'Duration must be at least 15 minutes.',
        ]);

        $start = Carbon::parse($this->bulkStartTime);
        $end = Carbon::parse($this->bulkEndTime);
        $createdCount = 0;

        $current = $start->copy();
        while ($current->copy()->addMinutes($this->bulkDurationMinutes)->lte($end)) {
            Shift::create([
                'event_configuration_id' => $this->eventConfig->id,
                'shift_role_id' => $this->bulkRoleId,
                'start_time' => $current->copy(),
                'end_time' => $current->copy()->addMinutes($this->bulkDurationMinutes),
                'capacity' => $this->bulkCapacity,
                'is_open' => $this->bulkIsOpen,
            ]);

            $current->addMinutes($this->bulkDurationMinutes);
            $createdCount++;
        }

        if ($current->lt($end)) {
            Shift::create([
                'event_configuration_id' => $this->eventConfig->id,
                'shift_role_id' => $this->bulkRoleId,
                'start_time' => $current->copy(),
                'end_time' => $end->copy(),
                'capacity' => $this->bulkCapacity,
                'is_open' => $this->bulkIsOpen,
            ]);

            $createdCount++;
        }

        AuditLog::log(
            action: 'shift.bulk_created',
            auditable: $this->eventConfig,
            newValues: [
                'role' => ShiftRole::find($this->bulkRoleId)->name,
                'count' => $createdCount,
                'duration_minutes' => $this->bulkDurationMinutes,
                'start_time' => $this->bulkStartTime,
                'end_time' => $this->bulkEndTime,
            ]
        );

        $this->showBulkModal = false;
        $this->resetBulkForm();
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: "{$createdCount} shifts created successfully", icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Assignments ---

    public function openAssignModal(int $shiftId): void
    {
        $this->assignShiftId = $shiftId;
        $this->assignUserId = null;
        $this->showAssignModal = true;
    }

    public function assignUser(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'assignShiftId' => ['required', 'exists:shifts,id'],
            'assignUserId' => ['required', 'exists:users,id'],
        ]);

        $shift = Shift::findOrFail($this->assignShiftId);

        // Check capacity
        if (! $shift->has_capacity) {
            $this->showAssignModal = false;
            $this->dispatch('toast', title: 'Error', description: 'This shift is already at capacity.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        // Check for duplicate
        $alreadyAssigned = ShiftAssignment::where('shift_id', $this->assignShiftId)
            ->where('user_id', $this->assignUserId)
            ->exists();

        if ($alreadyAssigned) {
            $this->showAssignModal = false;
            $this->dispatch('toast', title: 'Error', description: 'User is already assigned to this shift.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $assignment = ShiftAssignment::create([
            'shift_id' => $this->assignShiftId,
            'user_id' => $this->assignUserId,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ]);

        $user = User::find($this->assignUserId);
        AuditLog::log(
            action: 'shift.assigned',
            auditable: $assignment,
            newValues: [
                'assigned_user' => $user->call_sign,
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
            ]
        );

        $this->showAssignModal = false;
        $this->assignShiftId = null;
        $this->assignUserId = null;
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'User assigned to shift', icon: 'o-check-circle', css: 'alert-success');
    }

    public function removeAssignment(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user', 'shift.shiftRole');

        AuditLog::log(
            action: 'shift.removed',
            auditable: $assignment,
            oldValues: [
                'removed_user' => $assignment->user->call_sign,
                'role' => $assignment->shift->shiftRole->name,
            ]
        );

        $assignment->delete();

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Assignment removed', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Confirmations ---

    public function confirmCheckIn(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user');
        $assignment->confirm(Auth::user());

        AuditLog::log(
            action: 'shift.confirmed',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'confirmed_by' => Auth::user()->call_sign,
                'user' => $assignment->user->call_sign,
            ]
        );

        unset($this->pendingConfirmations);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Check-in confirmed', icon: 'o-check-circle', css: 'alert-success');
    }

    public function revokeConfirmation(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user');
        $assignment->revokeConfirmation();

        AuditLog::log(
            action: 'shift.revoked',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
            ]
        );

        unset($this->pendingConfirmations);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Confirmation revoked', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Manager Overrides ---

    public function managerCheckIn(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user');
        $assignment->checkIn();

        AuditLog::log(
            action: 'shift.manager_checkin',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
                'managed_by' => Auth::user()->call_sign,
            ]
        );

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'User checked in', icon: 'o-check-circle', css: 'alert-success');
    }

    public function managerCheckOut(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user');
        $assignment->checkOut();

        AuditLog::log(
            action: 'shift.manager_checkout',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
                'managed_by' => Auth::user()->call_sign,
            ]
        );

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'User checked out', icon: 'o-check-circle', css: 'alert-success');
    }

    public function markNoShow(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load('user');
        $assignment->markNoShow();

        AuditLog::log(
            action: 'shift.no_show',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
                'marked_by' => Auth::user()->call_sign,
            ]
        );

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Marked as no-show', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Form Resets ---

    protected function resetRoleForm(): void
    {
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->roleDescription = '';
        $this->roleBonusPoints = null;
        $this->roleRequiresConfirmation = false;
        $this->roleIcon = 'o-user-group';
        $this->roleColor = self::DEFAULT_ROLE_COLOR;
    }

    protected function resetShiftForm(): void
    {
        $this->editingShiftId = null;
        $this->shiftRoleId = null;
        $this->shiftStartTime = '';
        $this->shiftEndTime = '';
        $this->shiftCapacity = 1;
        $this->shiftIsOpen = true;
        $this->shiftNotes = '';
    }

    protected function resetBulkForm(): void
    {
        $this->bulkRoleId = null;
        $this->bulkStartTime = '';
        $this->bulkEndTime = '';
        $this->bulkDurationMinutes = 120;
        $this->bulkCapacity = 1;
        $this->bulkIsOpen = true;
    }

    public function render(): View
    {
        return view('livewire.schedule.manage-schedule')->layout('layouts.app');
    }
}
