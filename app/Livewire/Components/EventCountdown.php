<?php

namespace App\Livewire\Components;

use App\Models\Event;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EventCountdown extends Component
{
    public ?Event $event = null;

    public string $state = '';

    public int $pollingInterval = 60;

    public array $countdown = [];

    public string $timezoneLabel = '';

    public string $timezone = 'UTC';

    public ?int $targetTimestamp = null;

    public int $serverTimestamp = 0;

    public string $label = '';

    public string $badgeClass = '';

    public string $textClass = '';

    public function mount(): void
    {
        $this->updateComponent();
    }

    public function updateComponent(): void
    {
        $this->event = $this->getRelevantEvent();

        if (! $this->event) {
            return;
        }

        $this->state = $this->determineState();
        $this->calculateCountdown();
        $this->updateTimezone();
        $this->determinePollingInterval();
        $this->setStateStyles();
    }

    protected function getRelevantEvent(): ?Event
    {
        // Priority 1: Active event (currently in date range)
        $activeEvent = Event::active()->first();

        if ($activeEvent) {
            return $activeEvent;
        }

        // Priority 2: Compare recently completed vs upcoming events
        return $this->resolveNearestEvent();
    }

    /**
     * Resolve the nearest relevant event when no active event exists.
     *
     * Prefers upcoming events within 4 weeks over recently completed,
     * then completed within 4 weeks, then any upcoming.
     */
    protected function resolveNearestEvent(): ?Event
    {
        $completed = Event::completed()->orderBy('end_time', 'desc')->first();
        $completedWithin4Weeks = $completed && abs(appNow()->diffInDays($completed->end_time, false)) <= 28;

        $upcoming = Event::upcoming()->orderBy('start_time')->first();
        $upcomingWithin4Weeks = $upcoming && abs(appNow()->diffInDays($upcoming->start_time, false)) < 28;

        if ($completedWithin4Weeks && $upcomingWithin4Weeks) {
            return $upcoming;
        }

        return $completedWithin4Weeks ? $completed : $upcoming;
    }

    protected function determineState(): string
    {
        if (! $this->event) {
            return '';
        }

        $now = appNow();

        return match (true) {
            $this->event->start_time <= $now && $this->event->end_time >= $now => 'active',
            $this->event->setup_allowed_from && $this->event->setup_allowed_from <= $now && $this->event->start_time > $now => 'setup',
            $this->event->start_time > $now => 'upcoming',
            $this->event->end_time < $now => 'ended',
            default => '',
        };
    }

    protected function calculateCountdown(): void
    {
        if (! $this->event) {
            $this->countdown = [];

            return;
        }

        $targetTime = match ($this->state) {
            'upcoming', 'setup' => $this->event->start_time,
            'active' => $this->event->end_time,
            'ended' => $this->event->end_time,
            default => null,
        };

        if (! $targetTime) {
            $this->countdown = [];

            return;
        }

        $now = appNow();
        $diff = $this->state === 'ended'
            ? $now->diff($targetTime)
            : $targetTime->diff($now);

        $this->countdown = [
            'days' => $diff->days,
            'hours' => $diff->h,
            'minutes' => $diff->i,
            'seconds' => $diff->s,
        ];

        $this->targetTimestamp = $targetTime ? (int) $targetTime->timestamp : null;
    }

    protected function updateTimezone(): void
    {
        // Use user's preferred timezone if authenticated, otherwise use system timezone
        $timezone = auth()->check() && auth()->user()->preferred_timezone
            ? auth()->user()->preferred_timezone
            : Setting::get('timezone', config('app.timezone'));

        $now = appNow();

        // Get timezone abbreviation (e.g., EST, EDT, PST, PDT)
        $this->timezoneLabel = $now->timezone($timezone)->format('T');
        $this->timezone = $timezone;
        $this->serverTimestamp = (int) $now->timestamp;
    }

    protected function determinePollingInterval(): void
    {
        // Slow poll for event state sync; clocks and countdown tick client-side via Alpine
        $this->pollingInterval = 30;
    }

    protected function setStateStyles(): void
    {
        match ($this->state) {
            'setup' => [
                $this->label = 'Setup Open · Starts in',
                $this->badgeClass = 'badge-warning',
                $this->textClass = 'text-warning',
            ],
            'upcoming' => [
                $this->label = 'Starts in',
                $this->badgeClass = 'badge-info',
                $this->textClass = 'text-info',
            ],
            'active' => [
                $this->label = 'Ends in',
                $this->badgeClass = 'badge-success',
                $this->textClass = 'text-success',
            ],
            'ended' => [
                $this->label = 'Ended',
                $this->badgeClass = 'badge-neutral',
                $this->textClass = 'text-neutral',
            ],
            default => [
                $this->label = '',
                $this->badgeClass = '',
                $this->textClass = '',
            ],
        };
    }

    public function getFormattedCountdownProperty(): string
    {
        if (empty($this->countdown)) {
            return '';
        }

        ['days' => $days, 'hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds] = $this->countdown;

        return match (true) {
            $days > 0 => "{$days}d {$hours}h {$minutes}m",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m {$seconds}s",
        };
    }

    public function render(): View
    {
        return view('livewire.components.event-countdown');
    }
}
