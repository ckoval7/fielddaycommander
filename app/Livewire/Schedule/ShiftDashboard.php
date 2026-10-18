<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Schedule;

use App\Livewire\Schedule\Concerns\WithShiftAssignment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftRole;
use App\Services\EventContextService;
use App\Support\ShiftCoverage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ShiftDashboard extends Component
{
    use WithShiftAssignment;

    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    public function mount(): void
    {
        $this->event = $this->getDefaultEvent();
        $this->eventConfig = $this->event?->eventConfiguration;
    }

    /**
     * Coverage calculator for every shift in the event, or null when there is nothing to chart.
     */
    #[Computed]
    public function coverage(): ?ShiftCoverage
    {
        if (! $this->eventConfig) {
            return null;
        }

        $shifts = Shift::query()
            ->forEvent($this->eventConfig->id)
            ->with(['shiftRole', 'assignments.user'])
            ->orderBy('start_time')
            ->get();

        if ($shifts->isEmpty()) {
            return null;
        }

        $roles = ShiftRole::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->get();

        return ShiftCoverage::forWindow($shifts, $roles, $this->event?->start_time, $this->event?->end_time);
    }

    protected function shiftAssigned(): void
    {
        unset($this->coverage);
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
        return view('livewire.schedule.shift-dashboard')->layout('layouts.app');
    }
}
