<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;

/**
 * Session-aware event context service.
 *
 * Wraps the existing date-based active event logic with session override
 * support, allowing users to browse past events while preserving the
 * ability to query the real active event.
 *
 * Registered as a singleton in the service container. The old
 * ActiveEventService class is aliased to this for backward compatibility.
 */
class EventContextService extends ActiveEventService
{
    /** Session key for the viewing-event override. */
    protected const SESSION_KEY = 'viewing_event_id';

    /** Cached context event instance (session override or active fallback). */
    protected ?Event $contextEvent = null;

    /** Whether the context event has been loaded. */
    protected bool $contextLoaded = false;

    /**
     * Get the context event — session override, or active event fallback.
     *
     * If a session override is set, this returns that event (even if it is
     * in the past). Otherwise, falls back to the date-based active event.
     */
    public function getContextEvent(): ?Event
    {
        if (! $this->contextLoaded) {
            $overrideId = session($this::SESSION_KEY);

            if ($overrideId !== null) {
                $this->contextEvent = Event::where('id', $overrideId)
                    ->with('eventConfiguration')
                    ->first();
            } else {
                $this->contextEvent = $this->getActiveEvent()
                    ?? $this->getGracePeriodEvent()
                    ?? $this->getNearestUpcomingEvent();
            }

            $this->contextLoaded = true;
        }

        return $this->contextEvent;
    }

    /**
     * Get the nearest upcoming event (earliest future start_time).
     */
    protected function getNearestUpcomingEvent(): ?Event
    {
        return Event::upcoming()
            ->orderBy('start_time')
            ->with('eventConfiguration')
            ->first();
    }

    /**
     * Get the most recently completed event still within its grace period.
     */
    protected function getGracePeriodEvent(): ?Event
    {
        $graceDays = (int) Setting::get('post_event_grace_period_days', 30);

        return Event::completed()
            ->where('end_time', '>=', appNow()->subDays($graceDays))
            ->orderByDesc('end_time')
            ->with('eventConfiguration')
            ->first();
    }

    /**
     * Get the event configuration from the context event.
     *
     * Overrides the parent to use the context event rather than just
     * the active event.
     */
    public function getEventConfiguration(): ?EventConfiguration
    {
        return $this->getContextEvent()?->eventConfiguration;
    }

    /**
     * Get the context event ID, or 'no-event' for cache keys.
     */
    public function getContextEventId(): int|string
    {
        return $this->getContextEvent()?->id ?? 'no-event';
    }

    /**
     * Check if there is a context event available.
     */
    public function hasContextEvent(): bool
    {
        return $this->getContextEvent() !== null;
    }

    /**
     * Whether the user is viewing a past (non-active) event via session override.
     *
     * Returns true only when a session override is set AND it differs from
     * the currently active event.
     */
    public function isViewingPastEvent(): bool
    {
        $overrideId = session($this::SESSION_KEY);

        if ($overrideId === null) {
            return false;
        }

        $activeId = $this->getActiveEvent()?->id;

        return (int) $overrideId !== $activeId;
    }

    /**
     * Set the viewing event override in the session.
     *
     * Stores the event ID and clears the context cache so the next
     * call to getContextEvent() will pick up the new override.
     */
    public function setViewingEvent(int $eventId): void
    {
        session([$this::SESSION_KEY => $eventId]);
        $this->clearContextCache();
    }

    /**
     * Clear the viewing event override from the session.
     *
     * Reverts to the date-based active event.
     */
    public function clearViewingEvent(): void
    {
        session()->forget($this::SESSION_KEY);
        $this->clearContextCache();
    }

    /**
     * Determine the grace period status for an event.
     *
     * Status tiers:
     *   - 'active'   — currently within start_time / end_time
     *   - 'grace'    — ended less than X days ago (configurable)
     *   - 'archived' — ended more than X days ago
     *   - 'upcoming' — start_time is in the future
     *
     * Uses appNow() for all time comparisons (dev mode time travel).
     *
     * @param  Event|null  $event  The event to check (defaults to context event)
     */
    public function getGracePeriodStatus(?Event $event = null): string
    {
        $event ??= $this->getContextEvent();

        if ($event === null) {
            return 'archived';
        }

        $now = appNow();

        return match (true) {
            $event->setup_allowed_from && $event->start_time && $event->setup_allowed_from->lte($now) && $event->start_time->gt($now) => 'setup',
            $event->start_time && $event->start_time->gt($now) => 'upcoming',
            $event->start_time && $event->end_time && $event->start_time->lte($now) && $event->end_time->gte($now) => 'active',
            $event->end_time && $event->end_time->lt($now) => $this->resolvePostEventStatus($event, $now),
            default => 'archived',
        };
    }

    /**
     * Determine whether a completed event is in grace period or archived.
     */
    protected function resolvePostEventStatus(Event $event, \Carbon\Carbon $now): string
    {
        $graceDays = (int) Setting::get('post_event_grace_period_days', 30);

        return $now->lte($event->end_time->copy()->addDays($graceDays)) ? 'grace' : 'archived';
    }

    /**
     * Clear both the context cache and the parent active-event cache.
     */
    public function clearCache(): void
    {
        parent::clearCache();
        $this->clearContextCache();
    }

    /**
     * Clear only the context-event cache (not the active-event cache).
     */
    protected function clearContextCache(): void
    {
        $this->contextEvent = null;
        $this->contextLoaded = false;
    }
}
