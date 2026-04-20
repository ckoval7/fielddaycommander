<?php

namespace App\Livewire\Schedule;

use App\Livewire\Schedule\Concerns\WithScheduleFilters;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\ShiftAssignment;
use App\Services\EventContextService;
use App\Support\VolunteerHours;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MyShifts extends Component
{
    use WithScheduleFilters;

    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    public function mount(): void
    {
        $this->event = $this->getDefaultEvent();
        $this->eventConfig = $this->event?->eventConfiguration;
    }

    /**
     * Get current shifts — assignments where the shift is happening now.
     *
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function currentShifts(): Collection
    {
        if (! $this->eventConfig) {
            return new Collection;
        }

        $query = ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('start_time', '<=', appNow())
                    ->where('end_time', '>=', appNow());
            })
            ->with(['shift.shiftRole']);

        $this->applyAssignmentFilters($query);
        $this->applyAssignmentSorting($query);

        return $query->get();
    }

    /**
     * Get upcoming shifts — assignments where the shift hasn't started yet.
     *
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function upcomingShifts(): Collection
    {
        if (! $this->eventConfig) {
            return new Collection;
        }

        $query = ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('start_time', '>', appNow());
            })
            ->with(['shift.shiftRole']);

        $this->applyAssignmentFilters($query);
        $this->applyAssignmentSorting($query);

        return $query->get();
    }

    /**
     * Get past shifts — assignments where the shift has ended.
     *
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function pastShifts(): Collection
    {
        if (! $this->eventConfig) {
            return new Collection;
        }

        $query = ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('end_time', '<', appNow());
            })
            ->with(['shift.shiftRole']);

        $this->applyAssignmentFilters($query);
        $this->applyAssignmentSorting($query);

        return $query->get();
    }

    /**
     * All assignments for the current user scoped to the active event, eager-loading shift.
     *
     * Memoized so a single render can read both hour totals without issuing two queries.
     *
     * @return Collection<int, ShiftAssignment>
     */
    #[Computed]
    public function eventAssignments(): Collection
    {
        if (! $this->eventConfig) {
            return new Collection;
        }

        return ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', fn ($q) => $q->where('event_configuration_id', $this->eventConfig->id))
            ->with('shift')
            ->get();
    }

    /**
     * Shift-hour sum and overlap-merged wall-clock hours, for both worked and signed up,
     * across all the current user's assignments in the active event.
     *
     * No-show assignments are excluded. Keys:
     *   - worked_sum, worked_wall_clock: from actual check-in/check-out intervals
     *   - signed_up_sum, signed_up_wall_clock: from scheduled shift windows
     *
     * @return array{worked_sum: float, worked_wall_clock: float, signed_up_sum: float, signed_up_wall_clock: float}
     */
    #[Computed]
    public function eventHoursSummary(): array
    {
        if (! $this->eventConfig) {
            return [
                'worked_sum' => 0.0,
                'worked_wall_clock' => 0.0,
                'signed_up_sum' => 0.0,
                'signed_up_wall_clock' => 0.0,
            ];
        }

        $assignments = $this->eventAssignments
            ->where('status', '!=', ShiftAssignment::STATUS_NO_SHOW);

        return [
            'worked_sum' => VolunteerHours::sumHoursWorked($assignments),
            'worked_wall_clock' => VolunteerHours::wallClockHoursWorked($assignments),
            'signed_up_sum' => VolunteerHours::sumHoursScheduled($assignments),
            'signed_up_wall_clock' => VolunteerHours::wallClockHoursScheduled($assignments),
        ];
    }

    /**
     * Get available statuses for the filter dropdown.
     *
     * @return array<string, string>
     */
    public function getFilterStatuses(): array
    {
        return [
            'scheduled' => 'Scheduled',
            'checked_in' => 'Checked In',
            'checked_out' => 'Checked Out',
            'no_show' => 'No Show',
        ];
    }

    /**
     * Check in to an assigned shift.
     */
    public function checkIn(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
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

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'You have checked in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Check out of a checked-in shift.
     */
    public function checkOut(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
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

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'You have checked out.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Re-check-in to a checked-out shift, only while the shift is still active.
     */
    public function reCheckIn(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
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

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'You have checked back in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Drop a self-signup assignment that is still in scheduled status.
     */
    public function cancelSignUp(int $assignmentId): void
    {
        $this->authorize('sign-up-shifts');

        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
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

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'Shift has been dropped.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Get the default event to display (active, upcoming, or most recent).
     */
    private function getDefaultEvent(): ?Event
    {
        $contextEvent = app(EventContextService::class)->getContextEvent();
        if ($contextEvent) {
            return $contextEvent;
        }

        $currentEvent = Event::where('is_current', true)->first();
        if ($currentEvent) {
            return $currentEvent;
        }

        $upcomingEvent = Event::upcoming()
            ->orderBy('start_time', 'asc')
            ->first();
        if ($upcomingEvent) {
            return $upcomingEvent;
        }

        return Event::completed()
            ->orderBy('end_time', 'desc')
            ->first();
    }

    public function render(): View
    {
        return view('livewire.schedule.my-shifts')->layout('layouts.app');
    }
}
