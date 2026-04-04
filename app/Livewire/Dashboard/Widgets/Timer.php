<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Services\EventContextService;
use Livewire\Component;

/**
 * Timer Widget
 *
 * Displays a countdown timer to the active event's end time.
 * Updates every second via client-side JavaScript (Alpine.js).
 *
 * Timer Type: event_countdown
 * - Counts down to event end_time
 * - Format: "DD:HH:MM:SS" (if >= 24 hours) or "HH:MM:SS" (if < 24 hours)
 * - Shows "Event Ended" when event is over
 * - Changes color when < 1 hour remaining
 *
 * Config structure:
 * [
 *   'timer_type' => 'event_countdown'
 * ]
 */
class Timer extends Component
{
    use IsWidget;

    /**
     * Fetch the timer data for this widget.
     *
     * Returns the event end time, current time, and status.
     */
    public function getData(): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event) {
            return $this->noEventData();
        }

        $now = appNow();
        $startTime = $event->start_time;
        $endTime = $event->end_time;
        $isSetup = $event->setup_allowed_from
            && $startTime->isAfter($now)
            && $event->setup_allowed_from->lte($now);

        if ($isSetup) {
            return [
                'end_time' => $startTime->toIso8601String(),
                'now' => $now->toIso8601String(),
                'is_ended' => false,
                'label' => 'Event Starts In',
                'last_updated_at' => appNow(),
            ];
        }

        $isEnded = $now->greaterThan($endTime);

        return [
            'end_time' => $endTime->toIso8601String(),
            'now' => $now->toIso8601String(),
            'is_ended' => $isEnded,
            'label' => $isEnded ? 'Event Ended' : 'Time Remaining',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Define Livewire event listeners for this widget.
     *
     * Returns empty array - countdown is managed client-side.
     */
    public function getWidgetListeners(): array
    {
        return [];
    }

    /**
     * Timer widget should not use caching for real-time countdown.
     */
    public function shouldCache(): bool
    {
        return false;
    }

    /**
     * Return data when no active event exists.
     */
    protected function noEventData(): array
    {
        return [
            'end_time' => null,
            'now' => appNow()->toIso8601String(),
            'is_ended' => true,
            'label' => 'No Active Event',
            'last_updated_at' => appNow(),
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.widgets.timer', [
            'data' => $this->getData(),
        ]);
    }
}
