<?php

namespace App\Livewire\Schedule;

use App\Livewire\Schedule\Concerns\WithScheduleFilters;
use App\Livewire\Schedule\Concerns\WithShiftAssignment;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Services\EventContextService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ManageSchedule extends Component
{
    use WithScheduleFilters;
    use WithShiftAssignment;

    private const DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const DEFAULT_ROLE_COLOR = '#64748b';

    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    // Tab state
    #[Url(as: 'tab', except: 'shifts')]
    public string $activeTab = 'shifts';

    // Role modal
    public bool $showRoleModal = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    public string $roleDescription = '';

    public ?int $roleBonusPoints = null;

    public bool $roleRequiresConfirmation = false;

    public string $roleIcon = 'phosphor-users-three';

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
            $this->roleIcon = $role->icon ?? 'phosphor-users-three';
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

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'phosphor-check-circle', css: 'alert-success');
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
            $this->dispatch('toast', title: 'Error', description: 'Cannot delete role with assigned shifts. Remove assignments first.', icon: 'phosphor-x-circle', css: 'alert-error');

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

        $this->dispatch('toast', title: 'Success', description: 'Role deleted successfully', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    // --- Shift CRUD ---

    public function openShiftModal(?int $shiftId = null): void
    {
        $this->resetShiftForm();

        if ($shiftId) {
            $shift = Shift::findOrFail($shiftId);
            $this->editingShiftId = $shift->id;
            $this->shiftRoleId = $shift->shift_role_id;
            $this->shiftStartTime = toLocalTime($shift->start_time)->format(self::DATETIME_LOCAL_FORMAT);
            $this->shiftEndTime = toLocalTime($shift->end_time)->format(self::DATETIME_LOCAL_FORMAT);
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

        $tz = localTimezone();

        $data = [
            'shift_role_id' => $this->shiftRoleId,
            'start_time' => Carbon::parse($this->shiftStartTime, $tz)->utc(),
            'end_time' => Carbon::parse($this->shiftEndTime, $tz)->utc(),
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

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: $description, icon: 'phosphor-check-circle', css: 'alert-success');
    }

    // --- Bulk Creation ---

    public function openBulkModal(): void
    {
        $this->resetBulkForm();

        // Pre-fill with event start/end times
        if ($this->event) {
            $this->bulkStartTime = toLocalTime($this->event->start_time)?->format(self::DATETIME_LOCAL_FORMAT) ?? '';
            $this->bulkEndTime = toLocalTime($this->event->end_time)?->format(self::DATETIME_LOCAL_FORMAT) ?? '';
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

        $tz = localTimezone();
        $start = Carbon::parse($this->bulkStartTime, $tz)->utc();
        $end = Carbon::parse($this->bulkEndTime, $tz)->utc();
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

        $this->dispatch('toast', title: 'Success', description: "{$createdCount} shifts created successfully", icon: 'phosphor-check-circle', css: 'alert-success');
    }

    // --- Assignments ---

    protected function shiftAssigned(): void
    {
        unset($this->shifts);
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

        $this->dispatch('toast', title: 'Success', description: 'Assignment removed', icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: 'Check-in confirmed', icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: 'Confirmation revoked', icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: 'User checked in', icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: 'User checked out', icon: 'phosphor-check-circle', css: 'alert-success');
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

        $this->dispatch('toast', title: 'Success', description: 'Marked as no-show', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function markWorked(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load(['user', 'shift']);

        $this->applyMarkWorked($assignment);

        unset($this->shifts, $this->unresolvedPastAssignmentCount);

        $this->dispatch('toast', title: 'Success', description: 'Marked as worked', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Mark every unresolved assignment on an ended shift as worked.
     * No-shows and real check-outs are left alone.
     */
    public function markShiftWorked(int $shiftId): void
    {
        Gate::authorize('manage-shifts');

        $shift = Shift::findOrFail($shiftId);

        $count = $this->markUnresolvedWorked(
            ShiftAssignment::query()->where('shift_id', $shift->id)
        );

        $this->dispatch('toast', title: 'Success', description: $this->markedWorkedMessage($count), icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Mark every unresolved assignment on every ended shift in the event as worked.
     */
    public function markAllPastWorked(): void
    {
        Gate::authorize('manage-shifts');

        if (! $this->eventConfig) {
            return;
        }

        $count = $this->markUnresolvedWorked(
            ShiftAssignment::query()->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id);
            })
        );

        $this->dispatch('toast', title: 'Success', description: $this->markedWorkedMessage($count), icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Number of assignments on ended shifts in this event still awaiting worked/no-show.
     */
    #[Computed]
    public function unresolvedPastAssignmentCount(): int
    {
        if (! $this->eventConfig) {
            return 0;
        }

        return ShiftAssignment::query()
            ->unresolved()
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('end_time', '<', appNow());
            })
            ->count();
    }

    public function unmarkWorked(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->load(['user', 'shift']);

        $assignment->status = ShiftAssignment::STATUS_SCHEDULED;
        $assignment->checked_in_at = null;
        $assignment->checked_out_at = null;
        $assignment->confirmed_by_user_id = null;
        $assignment->confirmed_at = null;
        $assignment->save();

        AuditLog::log(
            action: 'shift.unmark_worked_by_manager',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
                'managed_by' => Auth::user()->call_sign,
            ]
        );

        unset($this->shifts, $this->unresolvedPastAssignmentCount);

        $this->dispatch('toast', title: 'Success', description: 'Mark Worked undone', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    // --- Mark Worked Helpers ---

    /**
     * Credit the assignment with the full shift window and confirm it.
     */
    private function applyMarkWorked(ShiftAssignment $assignment): void
    {
        $assignment->checked_in_at = $assignment->shift->start_time;
        $assignment->checked_out_at = $assignment->shift->end_time;
        $assignment->status = ShiftAssignment::STATUS_CHECKED_OUT;
        $assignment->confirmed_by_user_id = Auth::id();
        $assignment->confirmed_at = appNow();
        $assignment->save();

        AuditLog::log(
            action: 'shift.mark_worked_by_manager',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'user' => $assignment->user->call_sign,
                'managed_by' => Auth::user()->call_sign,
                'shift_start' => $assignment->shift->start_time->toDateTimeString(),
                'shift_end' => $assignment->shift->end_time->toDateTimeString(),
            ]
        );
    }

    /**
     * Mark the unresolved, ended-shift assignments matched by the query as worked.
     *
     * @param  Builder<ShiftAssignment>  $query
     */
    private function markUnresolvedWorked(Builder $query): int
    {
        $assignments = $query
            ->unresolved()
            ->whereHas('shift', fn ($shiftQuery) => $shiftQuery->where('end_time', '<', appNow()))
            ->with(['user', 'shift'])
            ->get();

        DB::transaction(function () use ($assignments) {
            $assignments->each(fn (ShiftAssignment $assignment) => $this->applyMarkWorked($assignment));
        });

        unset($this->shifts, $this->unresolvedPastAssignmentCount);

        return $assignments->count();
    }

    private function markedWorkedMessage(int $count): string
    {
        return $count === 0
            ? 'Nothing left to mark as worked'
            : 'Marked '.$count.' '.Str::plural('assignment', $count).' as worked';
    }

    // --- Form Resets ---

    protected function resetRoleForm(): void
    {
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->roleDescription = '';
        $this->roleBonusPoints = null;
        $this->roleRequiresConfirmation = false;
        $this->roleIcon = 'phosphor-users-three';
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

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'roleName' => 'name',
            'roleDescription' => 'description',
            'roleBonusPoints' => 'bonus points',
            'shiftRoleId' => 'role',
            'shiftStartTime' => 'start time',
            'shiftEndTime' => 'end time',
            'shiftCapacity' => 'capacity',
            'shiftNotes' => 'notes',
            'bulkRoleId' => 'role',
            'bulkStartTime' => 'start time',
            'bulkEndTime' => 'end time',
            'bulkDurationMinutes' => 'shift duration (minutes)',
            'bulkCapacity' => 'capacity',
            'assignUserId' => 'user',
            'assignShiftId' => 'shift',
        ];
    }
}
