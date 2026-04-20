<?php

namespace App\Livewire\Schedule;

use App\Livewire\Schedule\Concerns\WithScheduleFilters;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ScheduleTimeline extends Component
{
    use WithScheduleFilters;

    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    public function mount(): void
    {
        $this->event = $this->getDefaultEvent();
        $this->eventConfig = $this->event?->eventConfiguration;

        // Seed default roles if none exist for this event config
        if ($this->eventConfig && $this->eventConfig->shiftRoles()->count() === 0) {
            ShiftRole::seedDefaults($this->eventConfig);
        }
    }

    /**
     * Get filtered shifts as a flat collection.
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function filteredShifts(): Collection
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
     * Whether the current sort should flatten the role grouping.
     */
    #[Computed]
    public function isFlattened(): bool
    {
        return $this->sortBy !== 'role';
    }

    public function getFilterStatuses(): array
    {
        return [];
    }

    /**
     * Get shifts grouped by role for timeline display.
     *
     * @return array<int, array{role: ShiftRole, shifts: Collection<int, Shift>}>
     */
    #[Computed]
    public function shiftsByRole(): array
    {
        if (! $this->eventConfig) {
            return [];
        }

        $shifts = $this->filteredShifts;

        $roles = ShiftRole::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->get();

        if ($this->role !== '') {
            $roles = $roles->where('id', (int) $this->role);
        }

        $shiftsByRoleId = $shifts->groupBy('shift_role_id');

        return $roles
            ->map(fn (ShiftRole $role) => [
                'role' => $role,
                'shifts' => $shiftsByRoleId->get($role->id, collect()),
            ])
            ->filter(fn (array $group) => $group['shifts']->isNotEmpty())
            ->values()
            ->toArray();
    }

    /**
     * Get current user's assignments for this event.
     *
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function myAssignments(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return ShiftAssignment::query()
            ->forUser(auth()->id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id);
            })
            ->with(['shift.shiftRole'])
            ->get();
    }

    /**
     * Sign up for an open shift.
     */
    public function signUp(int $shiftId): void
    {
        $this->authorize('sign-up-shifts');

        $shift = Shift::findOrFail($shiftId);

        $error = match (true) {
            $shift->is_past => 'This shift has already ended.',
            ! $shift->is_open => 'This shift is not open for sign-ups.',
            ! $shift->has_capacity => 'This shift is full.',
            ShiftAssignment::where('shift_id', $shiftId)
                ->where('user_id', auth()->id())
                ->exists() => 'You are already signed up for this shift.',
            default => null,
        };

        if ($error !== null) {
            $this->dispatch('toast', title: 'Error', description: $error, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $assignment = ShiftAssignment::create([
            'shift_id' => $shiftId,
            'user_id' => auth()->id(),
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        AuditLog::log(
            action: 'shift.signup',
            auditable: $assignment,
            newValues: [
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
            ]
        );

        unset($this->filteredShifts);
        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have been signed up for the shift.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Cancel a self-signup assignment.
     */
    public function cancelSignUp(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('signup_type', ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        $assignment->load('shift.shiftRole');

        AuditLog::log(
            action: 'shift.signup.cancelled',
            auditable: $assignment,
            oldValues: [
                'role' => $assignment->shift->shiftRole->name,
                'start_time' => $assignment->shift->start_time->toIso8601String(),
                'end_time' => $assignment->shift->end_time->toIso8601String(),
            ]
        );

        $assignment->delete();

        unset($this->filteredShifts);
        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'Shift has been dropped.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Check in to an assigned shift.
     */
    public function checkIn(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        if (! $assignment->shift->can_check_in) {
            $this->dispatch('toast', title: 'Too Early', description: 'Check-in opens 15 minutes before the shift starts.', icon: 'phosphor-clock', css: 'alert-warning');

            return;
        }

        $assignment->checkIn();

        AuditLog::log(
            action: 'shift.checkin',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'role' => $assignment->shift->shiftRole->name,
            ]
        );

        unset($this->filteredShifts);
        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have checked in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Check out of a checked-in shift.
     */
    public function checkOut(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
            ->firstOrFail();

        $assignment->checkOut();

        AuditLog::log(
            action: 'shift.checkout',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'role' => $assignment->shift->shiftRole->name,
            ]
        );

        unset($this->filteredShifts);
        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have checked out.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Re-check-in to a checked-out shift, only while the shift is still active.
     */
    public function reCheckIn(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('status', ShiftAssignment::STATUS_CHECKED_OUT)
            ->firstOrFail();

        if (! $assignment->shift->is_current) {
            $this->dispatch('toast', title: 'Too Late', description: 'This shift has already ended.', icon: 'phosphor-clock', css: 'alert-warning');

            return;
        }

        $assignment->checkBackIn();

        AuditLog::log(
            action: 'shift.checkin',
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'role' => $assignment->shift->shiftRole->name,
            ]
        );

        unset($this->filteredShifts);
        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have checked back in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Get the default event to display (active, upcoming, or most recent).
     */
    private function getDefaultEvent(): ?Event
    {
        // 1. Try context event (session-overridden or active event)
        $contextEvent = app(EventContextService::class)->getContextEvent();
        if ($contextEvent) {
            return $contextEvent;
        }

        // 2. Try the event marked as current (for pre-event planning)
        $currentEvent = Event::where('is_current', true)->first();
        if ($currentEvent) {
            return $currentEvent;
        }

        // 3. Try next upcoming event
        $upcomingEvent = Event::upcoming()
            ->orderBy('start_time', 'asc')
            ->first();
        if ($upcomingEvent) {
            return $upcomingEvent;
        }

        // 4. Fall back to most recent past event
        return Event::completed()
            ->orderBy('end_time', 'desc')
            ->first();
    }

    public function render(): View
    {
        return view('livewire.schedule.schedule-timeline')->layout('layouts.app');
    }
}
