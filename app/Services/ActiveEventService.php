<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventConfiguration;

/**
 * Request-scoped cache for the active event and configuration.
 *
 * Prevents repeated Event::active()->first() queries during a single request.
 * All widgets and dashboard components should use this service instead of
 * querying the Event model directly.
 *
 * Registered as a singleton in the service container, so there's one instance
 * per request that all components share.
 */
class ActiveEventService
{
    /**
     * Cached active event instance.
     */
    protected ?Event $activeEvent = null;

    /**
     * Whether the active event has been loaded.
     *
     * Tracks whether we've queried for the event (null = not queried yet,
     * false = queried but no active event found).
     */
    protected bool $loaded = false;

    /**
     * Cached active-or-upcoming event instance.
     */
    protected ?Event $activeOrUpcomingEvent = null;

    /**
     * Whether the active-or-upcoming event has been loaded.
     */
    protected bool $activeOrUpcomingLoaded = false;

    /**
     * Get the active event with eventConfiguration eager-loaded.
     *
     * Queries the database on first call, then returns the cached instance
     * for subsequent calls within the same request.
     *
     * @return Event|null The active event, or null if no event is currently active
     */
    public function getActiveEvent(): ?Event
    {
        if (! $this->loaded) {
            $this->activeEvent = Event::active()
                ->with('eventConfiguration')
                ->first();

            $this->loaded = true;
        }

        return $this->activeEvent;
    }

    /**
     * Get the active event's configuration.
     *
     * Convenience method that returns null if no event is active or if the
     * event has no configuration record.
     */
    public function getEventConfiguration(): ?EventConfiguration
    {
        return $this->getActiveEvent()?->eventConfiguration;
    }

    /**
     * Get the active event ID.
     *
     * Returns a string 'no-event' when no event is active, for use in cache keys.
     *
     * @return int|string Event ID or 'no-event'
     */
    public function getActiveEventId(): int|string
    {
        return $this->getActiveEvent()?->id ?? 'no-event';
    }

    /**
     * Check if there is an active event.
     */
    public function hasActiveEvent(): bool
    {
        return $this->getActiveEvent() !== null;
    }

    /**
     * Get the nearest event that has not yet ended — either currently active or upcoming.
     *
     * Used by weather polling so forecasts and alerts are fetched before
     * an event starts, not only while it is in progress.
     */
    public function getActiveOrUpcomingEvent(): ?Event
    {
        if (! $this->activeOrUpcomingLoaded) {
            $this->activeOrUpcomingEvent = Event::where('end_time', '>=', appNow())
                ->with('eventConfiguration')
                ->orderBy('start_time')
                ->first();

            $this->activeOrUpcomingLoaded = true;
        }

        return $this->activeOrUpcomingEvent;
    }

    /**
     * Clear the cached event.
     *
     * Useful for testing or when you know the event status has changed
     * mid-request.
     */
    public function clearCache(): void
    {
        $this->activeEvent = null;
        $this->loaded = false;
        $this->activeOrUpcomingEvent = null;
        $this->activeOrUpcomingLoaded = false;
    }
}
