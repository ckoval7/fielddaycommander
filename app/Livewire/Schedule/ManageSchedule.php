<?php

namespace App\Livewire\Schedule;

use App\Enums\ChecklistType;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistItem;
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

    public string $roleColor = '#64748b';

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

        return Shift::query()
            ->forEvent($this->eventConfig->id)
            ->with(['shiftRole', 'assignments.user'])
            ->chronological()
            ->get();
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
            $this->roleColor = $role->color ?? '#64748b';
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
            $role->update($data);
            $message = 'Role updated successfully';
        } else {
            $maxSortOrder = ShiftRole::query()
                ->forEvent($this->eventConfig->id)
                ->max('sort_order') ?? -1;

            ShiftRole::create(array_merge($data, [
                'event_configuration_id' => $this->eventConfig->id,
                'is_default' => false,
                'sort_order' => $maxSortOrder + 1,
            ]));
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
            $this->shiftStartTime = $shift->start_time->format('Y-m-d\TH:i');
            $this->shiftEndTime = $shift->end_time->format('Y-m-d\TH:i');
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
            $shift->update($data);
            $message = 'Shift updated successfully';
        } else {
            Shift::create(array_merge($data, [
                'event_configuration_id' => $this->eventConfig->id,
            ]));
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
        $assignmentCount = $shift->assignments()->count();

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
            $this->bulkStartTime = $this->event->start_time?->format('Y-m-d\TH:i') ?? '';
            $this->bulkEndTime = $this->event->end_time?->format('Y-m-d\TH:i') ?? '';
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

        ShiftAssignment::create([
            'shift_id' => $this->assignShiftId,
            'user_id' => $this->assignUserId,
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ]);

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
        $assignment->delete();

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Assignment removed', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Confirmations ---

    public function confirmCheckIn(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $roleName = $assignment->shift?->shiftRole?->name;

        // Check checklist gate for safety/site responsibility roles
        if ($assignment->shift?->shiftRole?->isBonusEligibilityOnly()
            && in_array($roleName, ['Safety Officer', 'Site Responsibilities'])
            && ! $this->checklistGateMet($roleName)) {
            $this->dispatch('toast',
                title: 'Checklist Incomplete',
                description: 'The safety checklist must be completed before this bonus can be awarded.',
                icon: 'o-exclamation-triangle',
                css: 'alert-warning'
            );

            return;
        }

        $assignment->confirm(Auth::user());

        unset($this->pendingConfirmations);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Check-in confirmed', icon: 'o-check-circle', css: 'alert-success');
    }

    public function checklistGateMet(string $roleName): bool
    {
        if (! $this->eventConfig) {
            return false;
        }

        $checklistType = match ($roleName) {
            'Safety Officer' => ChecklistType::SafetyOfficer,
            'Site Responsibilities' => ChecklistType::SiteResponsibilities,
            default => null,
        };

        if (! $checklistType) {
            return true; // No gate for other roles
        }

        $items = SafetyChecklistItem::forEvent($this->eventConfig->id)
            ->byType($checklistType)
            ->with('entry')
            ->get();

        if ($items->isEmpty()) {
            return false; // No checklist seeded = can't verify
        }

        $totalCompleted = $items->filter(fn ($item) => $item->entry?->is_completed)->count();
        $requiredCompleted = $items->filter(fn ($item) => $item->is_required && $item->entry?->is_completed)->count();
        $requiredTotal = $items->filter(fn ($item) => $item->is_required)->count();

        if ($checklistType === ChecklistType::SafetyOfficer) {
            return $totalCompleted === $items->count();
        }

        // Site Responsibilities: all required + >50% total
        return $requiredCompleted === $requiredTotal && $totalCompleted > ($items->count() / 2);
    }

    public function revokeConfirmation(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->revokeConfirmation();

        unset($this->pendingConfirmations);
        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'Confirmation revoked', icon: 'o-check-circle', css: 'alert-success');
    }

    // --- Manager Overrides ---

    public function managerCheckIn(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->checkIn();

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'User checked in', icon: 'o-check-circle', css: 'alert-success');
    }

    public function managerCheckOut(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->checkOut();

        unset($this->shifts);

        $this->dispatch('toast', title: 'Success', description: 'User checked out', icon: 'o-check-circle', css: 'alert-success');
    }

    public function markNoShow(int $assignmentId): void
    {
        Gate::authorize('manage-shifts');

        $assignment = ShiftAssignment::findOrFail($assignmentId);
        $assignment->markNoShow();

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
        $this->roleColor = '#64748b';
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
