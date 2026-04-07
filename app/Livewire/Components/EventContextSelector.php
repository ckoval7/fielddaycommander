<?php

namespace App\Livewire\Components;

use App\Models\Event;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EventContextSelector extends Component
{
    /**
     * Switch to viewing a specific event by setting the session override.
     */
    public function switchEvent(int $eventId): void
    {
        if (! Event::where('id', $eventId)->exists()) {
            return;
        }

        $service = app(EventContextService::class);
        $service->setViewingEvent($eventId);

        $this->redirect(request()->header('Referer', '/'), navigate: true);
    }

    /**
     * Clear the session override and return to the active event.
     */
    public function returnToActive(): void
    {
        $service = app(EventContextService::class);
        $service->clearViewingEvent();

        $this->redirect(request()->header('Referer', '/'), navigate: true);
    }

    public function render(): View
    {
        $service = app(EventContextService::class);
        $contextEvent = $service->getContextEvent();
        $activeEvent = $service->getActiveEvent();
        $isViewingPast = $service->isViewingPastEvent();
        $gracePeriodStatus = $contextEvent ? $service->getGracePeriodStatus($contextEvent) : null;

        // Get all events with configurations, ordered by start_time desc
        $events = Event::with('eventConfiguration')
            ->orderByDesc('start_time')
            ->get();

        // Group events by status
        $grouped = [
            'active' => collect(),
            'grace' => collect(),
            'setup' => collect(),
            'upcoming' => collect(),
            'archived' => collect(),
        ];

        foreach ($events as $event) {
            $status = $service->getGracePeriodStatus($event);
            $grouped[$status]->push($event);
        }

        return view('livewire.components.event-context-selector', [
            'contextEvent' => $contextEvent,
            'activeEvent' => $activeEvent,
            'isViewingPast' => $isViewingPast,
            'gracePeriodStatus' => $gracePeriodStatus,
            'grouped' => $grouped,
        ]);
    }
}
