<?php

namespace App\Livewire\Schedule;

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

        $roles = ShiftRole::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->with(['shifts' => function ($query) {
                $query->chronological()->with(['assignments.user']);
            }])
            ->get();

        return $roles->map(fn (ShiftRole $role) => [
            'role' => $role,
            'shifts' => $role->shifts,
        ])->toArray();
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
        $shift = Shift::findOrFail($shiftId);

        if (! $shift->is_open) {
            $this->dispatch('toast', title: 'Error', description: 'This shift is not open for sign-ups.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        if (! $shift->has_capacity) {
            $this->dispatch('toast', title: 'Error', description: 'This shift is full.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $alreadyAssigned = ShiftAssignment::where('shift_id', $shiftId)
            ->where('user_id', auth()->id())
            ->exists();

        if ($alreadyAssigned) {
            $this->dispatch('toast', title: 'Error', description: 'You are already signed up for this shift.', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        ShiftAssignment::create([
            'shift_id' => $shiftId,
            'user_id' => auth()->id(),
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);

        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have been signed up for the shift.', icon: 'o-check-circle', css: 'alert-success');
    }

    /**
     * Cancel a self-signup assignment.
     */
    public function cancelSignUp(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('signup_type', ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        $assignment->delete();

        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'Your sign-up has been cancelled.', icon: 'o-check-circle', css: 'alert-success');
    }

    /**
     * Check in to an assigned shift.
     */
    public function checkIn(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        if (! $assignment->shift->can_check_in) {
            $this->dispatch('toast', title: 'Too Early', description: 'Check-in opens 15 minutes before the shift starts.', icon: 'o-clock', css: 'alert-warning');

            return;
        }

        $assignment->checkIn();

        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have checked in.', icon: 'o-check-circle', css: 'alert-success');
    }

    /**
     * Check out of a checked-in shift.
     */
    public function checkOut(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', auth()->id())
            ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
            ->firstOrFail();

        $assignment->checkOut();

        unset($this->shiftsByRole);
        unset($this->myAssignments);

        $this->dispatch('toast', title: 'Success', description: 'You have checked out.', icon: 'o-check-circle', css: 'alert-success');
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

        // 2. Try next upcoming event
        $upcomingEvent = Event::upcoming()
            ->orderBy('start_time', 'asc')
            ->first();
        if ($upcomingEvent) {
            return $upcomingEvent;
        }

        // 3. Fall back to most recent past event
        return Event::completed()
            ->orderBy('end_time', 'desc')
            ->first();
    }

    public function render(): View
    {
        return view('livewire.schedule.schedule-timeline')->layout('layouts::app');
    }
}
