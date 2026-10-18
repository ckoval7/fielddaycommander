<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets\Concerns;

use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;

/**
 * Foundation trait for all dashboard widgets.
 *
 * Provides common properties, lifecycle initialization, cache key generation,
 * and event dispatching that every widget component needs. Any Livewire
 * component that uses this trait must implement the abstract methods
 * getData() and getWidgetListeners().
 *
 * Cache key format: dashboard:widget:{type}:{config_hash}:{event_id}
 *
 * @property string $size Widget size variant ('normal' or 'tv')
 * @property array $config Widget configuration from dashboard config
 * @property string $widgetId Unique widget instance ID
 */
trait IsWidget
{
    use ListensForBroadcasts;

    /**
     * Widget size variant.
     *
     * Controls how the widget renders. 'normal' is for standard dashboards,
     * 'tv' is for the TV/kiosk display mode with larger fonts and layout.
     */
    public string $size = 'normal';

    /**
     * Widget configuration from the dashboard config.
     *
     * Contains widget-specific settings such as metric type, refresh interval,
     * display options, etc. Structure varies by widget type. Assigned by the
     * server at mount, so the browser may not change it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $config = [];

    /**
     * Unique widget instance identifier.
     *
     * Used for cache keys, event targeting, and DOM identification.
     * Auto-generated from class name and config hash if not provided.
     */
    public string $widgetId = '';

    /**
     * Fetch the data this widget needs to render.
     *
     * Each widget type implements this to query its specific data source
     * (e.g., contacts count, score breakdown, band activity).
     *
     * @return array<string, mixed>
     */
    abstract public function getData(): array;

    /**
     * Define the Livewire event listeners for this widget.
     *
     * Each widget type implements this to declare which events it responds to.
     * Return format: ['event-name' => 'methodName', ...]
     *
     * Named getWidgetListeners() to avoid conflicting with Livewire's
     * built-in getListeners() method on the Component class.
     *
     * @return array<string, string>
     */
    abstract public function getWidgetListeners(): array;

    /**
     * Initialize widget properties during Livewire mount.
     *
     * Uses Livewire 4's trait-prefixed hook convention so multiple traits
     * can coexist without conflicting mount() definitions.
     *
     * @param  array<string, mixed>  $config  Widget configuration
     * @param  string  $size  Display size variant ('normal' or 'tv')
     * @param  string|null  $widgetId  Optional explicit widget ID
     */
    public function mountIsWidget(array $config = [], string $size = 'normal', ?string $widgetId = null): void
    {
        $this->config = $config;
        $this->size = $size;
        $this->widgetId = $widgetId ?? $this->generateWidgetId();
    }

    /**
     * Register Livewire event listeners for this component.
     *
     * Automatically integrates widget-specific listeners from getWidgetListeners()
     * with Livewire's event system. Widgets define their listeners via
     * getWidgetListeners() which this method then registers.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->getWidgetListeners();
    }

    /**
     * Trigger a widget refresh and notify other components.
     *
     * Dispatches a 'widget-updated' Livewire event with the widget's ID,
     * allowing parent components (like DashboardManager) to react to
     * individual widget updates.
     */
    public function refresh(): void
    {
        $this->dispatch('widget-updated', widgetId: $this->widgetId);
    }

    /**
     * No-op handler for JavaScript's JSON.stringify($wire) calls.
     *
     * Livewire's $wire proxy forwards all unknown property accesses to the
     * server as method calls. JSON.stringify() calls toJSON() on objects,
     * and since the proxy doesn't exclude it, the call reaches the server.
     * This prevents a MethodNotFoundException during Alpine/Livewire morph.
     *
     * @return array<string, mixed>
     */
    public function toJSON(): array
    {
        return [];
    }

    /**
     * Handle real-time update notification.
     *
     * Called when widget data changes. Clears cache and dispatches
     * update event for visual feedback.
     */
    public function handleUpdate(): void
    {
        // Clear widget cache
        Cache::forget($this->cacheKey());

        // Notify frontend of update
        $this->dispatch('widget-updating', widgetId: $this->widgetId);

        // Force re-render
        $this->refresh();
    }

    /**
     * Generate a deterministic cache key unique to this widget instance.
     *
     * Format: dashboard:widget:{class_basename}:{config_md5}:{event_id}
     *
     * The key incorporates the widget type, its configuration, and the
     * current active event so cached data is automatically invalidated
     * when events change or config is modified.
     */
    public function cacheKey(): string
    {
        $type = class_basename(get_class($this));
        $configHash = hash('xxh128', json_encode($this->config));
        $contextService = app(EventContextService::class);
        $eventId = $contextService->getContextEventId();

        return "dashboard:widget:{$type}:{$configHash}:{$eventId}";
    }

    /**
     * Determine whether this widget should use caching.
     *
     * Widgets can override this to disable caching for real-time data
     * (e.g., timers, live feeds). Defaults to true; the actual cache
     * service implementation is handled by Task #30.
     */
    public function shouldCache(): bool
    {
        return true;
    }

    /**
     * Listener map for real-time contact broadcasts on the event channel.
     *
     * Contacts logged, edited or deleted are broadcast only on the private
     * event.{id} channel, and that channel authorizes signed-in users only.
     * Guests (e.g. the public TV dashboard) get no listener and rely on the
     * widget's polling instead of a subscription that would be refused.
     *
     * @return array<string, string>
     */
    protected function contactChangeListeners(string $method = 'handleUpdate'): array
    {
        // getContextEventId() returns a 'no-event' placeholder for cache keys,
        // so read the event itself to skip subscribing when there is none.
        $eventId = app(EventContextService::class)->getContextEvent()?->id;

        if ($eventId === null || ! $this->canListenForBroadcasts()) {
            return [];
        }

        return BroadcastChannels::contactChangeListeners($eventId, $method);
    }

    /**
     * Check if this widget is in TV/kiosk display mode.
     */
    public function isTvSize(): bool
    {
        return $this->size === 'tv';
    }

    /**
     * Generate a widget ID from the class name and config hash.
     *
     * Produces a deterministic ID so the same widget with the same config
     * always gets the same identifier, useful for DOM stability.
     */
    protected function generateWidgetId(): string
    {
        $type = class_basename(get_class($this));
        $configHash = substr(hash('xxh128', json_encode($this->config)), 0, 8);

        return strtolower($type).'-'.$configHash;
    }
}
