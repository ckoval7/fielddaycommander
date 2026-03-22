<?php

namespace App\Livewire\Schedule;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\ShiftAssignment;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MyShifts extends Component
{
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

        return ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('start_time', '<=', appNow())
                    ->where('end_time', '>=', appNow());
            })
            ->with(['shift.shiftRole'])
            ->get();
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

        return ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('start_time', '>', appNow());
            })
            ->with(['shift.shiftRole'])
            ->get();
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

        return ShiftAssignment::query()
            ->forUser(Auth::id())
            ->whereHas('shift', function ($query) {
                $query->where('event_configuration_id', $this->eventConfig->id)
                    ->where('end_time', '<', appNow());
            })
            ->with(['shift.shiftRole'])
            ->get();
    }

    /**
     * Check in to an assigned shift.
     */
    public function checkIn(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        if (! $assignment->shift->can_check_in) {
            $this->dispatch('toast', title: 'Too Early', description: 'Check-in opens 15 minutes before the shift starts.', icon: 'o-clock', css: 'alert-warning');

            return;
        }

        $assignment->checkIn();

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'You have checked in.', icon: 'o-check-circle', css: 'alert-success');
    }

    /**
     * Check out of a checked-in shift.
     */
    public function checkOut(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
            ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
            ->firstOrFail();

        $assignment->checkOut();

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'You have checked out.', icon: 'o-check-circle', css: 'alert-success');
    }

    /**
     * Cancel a self-signup assignment that is still in scheduled status.
     */
    public function cancelSignUp(int $assignmentId): void
    {
        $assignment = ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
            ->where('signup_type', ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
            ->where('status', ShiftAssignment::STATUS_SCHEDULED)
            ->firstOrFail();

        $assignment->delete();

        unset($this->currentShifts);
        unset($this->upcomingShifts);
        unset($this->pastShifts);

        $this->dispatch('toast', title: 'Success', description: 'Your sign-up has been cancelled.', icon: 'o-check-circle', css: 'alert-success');
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
        return view('livewire.schedule.my-shifts')->layout('layouts::app');
    }
}
