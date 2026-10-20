<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\HighlightsNewItems;
use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * ListWidget - Displays scrollable lists of data.
 *
 * Supports three list types:
 * - recent_contacts: Last N contacts with time, callsign, band, mode, operator
 * - active_stations: Currently active stations with operator, band, status
 * - equipment_status: Equipment usage with name, status, assigned_to
 *
 * Config structure:
 * [
 *   'list_type' => 'recent_contacts|active_stations|equipment_status'
 * ]
 *
 * Size variants:
 * - normal: 15 items, standard text
 * - tv: 10 items, larger text and spacing
 */
class ListWidget extends Component
{
    use HighlightsNewItems;
    use IsWidget {
        cacheKey as baseCacheKey;
    }

    /**
     * Most rows any list renders, whatever the stored settings say.
     */
    protected const MAX_ITEMS = 50;

    /**
     * List types any viewer, including guests on the public TV dashboard, may see.
     */
    protected const PUBLIC_LIST_TYPES = ['recent_contacts', 'active_stations'];

    /**
     * List types shown only to signed-in users. Net traffic can carry
     * medical or personal details, so it never reaches the public TV.
     */
    protected const MEMBER_LIST_TYPES = ['equipment_status', 'net_traffic'];

    protected const DEFAULT_LIST_TYPE = 'recent_contacts';

    /**
     * Fetch the list data for this widget.
     *
     * Returns an array of items formatted for display.
     */
    public function getData(): array
    {
        return Cache::remember(
            $this->cacheKey(),
            3,
            fn () => $this->fetchListData()
        );
    }

    /**
     * Define Livewire event listeners for this widget.
     *
     * Recent contacts also refresh immediately when a contact is logged,
     * edited or deleted and the viewer may subscribe to the event channel; every list type polls.
     */
    public function getWidgetListeners(): array
    {
        $listType = $this->listType();

        return match ($listType) {
            'recent_contacts' => $this->contactChangeListeners(),
            'net_traffic' => $this->netLogChangeListeners(),
            default => [],
        };
    }

    /**
     * Fetch the appropriate list data based on config.
     */
    protected function fetchListData(): array
    {
        $listType = $this->listType();
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event || ! $event->eventConfiguration) {
            return [
                'items' => [],
                'last_updated_at' => appNow(),
            ];
        }

        $items = match ($listType) {
            'recent_contacts' => $this->getRecentContacts($event),
            'active_stations' => $this->getActiveStations($event),
            'equipment_status' => $this->getEquipmentStatus($event),
            'net_traffic' => $this->getNetTraffic($event),
            default => [],
        };

        return [
            'items' => $items,
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get recent contacts list.
     *
     * Format: Time ago, callsign, band, mode, operator
     */
    protected function getRecentContacts(Event $event): array
    {
        $limit = $this->itemLimit();

        return Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->with(['band', 'mode', 'operatingSession.operator', 'operatingSession.station'])
            ->notDuplicate()
            ->chronological()
            ->limit($limit)
            ->get()
            ->map(function (Contact $contact) {
                return [
                    'type' => 'recent_contact',
                    'key' => "contact-{$contact->id}",
                    'time_ago' => $this->formatTimeAgo($contact->qso_time),
                    'callsign' => $contact->callsign,
                    'band' => $contact->band?->name ?? 'Unknown',
                    'mode' => $contact->mode?->name ?? 'Unknown',
                    'operator' => $contact->operatingSession?->operator?->call_sign ?? 'Unknown',
                    'station' => $contact->operatingSession?->station?->name ?? 'Unknown',
                ];
            })
            ->all();
    }

    /**
     * Get the newest net log entries.
     *
     * Format: Time ago, from, to, net (when the event has several), message, priority
     */
    protected function getNetTraffic(Event $event): array
    {
        $showsNetName = Net::forEvent($event->eventConfiguration->id)->count() > 1;

        return NetLogEntry::query()
            ->forEvent($event->eventConfiguration->id)
            ->with('net')
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit($this->itemLimit())
            ->get()
            ->map(fn (NetLogEntry $entry) => [
                'type' => 'net_entry',
                'key' => "net-entry-{$entry->id}",
                'time_ago' => $this->formatTimeAgo($entry->logged_at),
                'from' => $entry->fromLabel(),
                'to' => $entry->toLabel(),
                'net' => $showsNetName ? $entry->net->name : null,
                'message' => $entry->message,
                'priority' => $entry->priority->value,
            ])
            ->all();
    }

    /**
     * Refresh as soon as a net log entry changes, when the viewer may
     * subscribe to the event channel.
     *
     * @return array<string, string>
     */
    protected function netLogChangeListeners(): array
    {
        $eventId = $this->broadcastEventId();

        return $eventId === null ? [] : [
            BroadcastChannels::privateListener(BroadcastChannels::event($eventId), '.'.BroadcastChannels::NET_LOG_CHANGED) => 'handleUpdate',
        ];
    }

    /**
     * Get active stations list.
     *
     * Format: Station name, operator, current band, status
     */
    protected function getActiveStations(Event $event): array
    {
        $limit = $this->itemLimit();

        $activeSessions = OperatingSession::query()
            ->whereHas('station', function ($query) use ($event) {
                $query->where('event_configuration_id', $event->eventConfiguration->id);
            })
            ->with(['station', 'operator', 'band', 'mode'])
            ->active()
            ->orderBy('start_time', 'desc')
            ->limit($limit)
            ->get();

        return $activeSessions->map(function (OperatingSession $session) {
            return [
                'type' => 'active_station',
                'key' => "session-{$session->id}",
                'station_name' => $session->station?->name ?? 'Unknown',
                'operator_name' => $session->operator?->call_sign ?? 'Unknown',
                'band' => $session->band?->name ?? 'Unknown',
                'mode' => $session->mode?->name ?? 'Unknown',
                'status' => 'Active',
                'status_color' => 'success',
            ];
        })->all();
    }

    /**
     * Get equipment status list.
     *
     * Format: Equipment name, status, assigned to
     */
    protected function getEquipmentStatus(Event $event): array
    {
        $limit = $this->itemLimit();

        $commitments = EquipmentEvent::query()
            ->where('event_id', $event->id)
            ->with(['equipment', 'station'])
            ->whereIn('status', ['committed', 'delivered'])
            ->get()
            ->sortBy(function ($commitment) {
                // Sort by status priority: delivered, committed
                return match ($commitment->status) {
                    'delivered' => 1,
                    'committed' => 2,
                    default => 99,
                };
            })
            ->take($limit);

        return $commitments->map(function (EquipmentEvent $commitment) {
            $equipment = $commitment->equipment;
            $equipmentName = $equipment
                ? trim("{$equipment->make} {$equipment->model}")
                : 'Unknown Equipment';

            return [
                'type' => 'equipment',
                'key' => "equipment-{$commitment->id}",
                'equipment_name' => $equipmentName,
                'status' => ucfirst($commitment->status),
                'status_color' => $this->getStatusColor($commitment->status),
                'assigned_to' => $commitment->station?->name ?? 'Unassigned',
            ];
        })->values()->all();
    }

    /**
     * Include the resolved list type so guests never read a member-only list from cache.
     */
    public function cacheKey(): string
    {
        return $this->baseCacheKey().':'.$this->listType();
    }

    /**
     * Resolve the configured list type, falling back to recent contacts for
     * unknown types and for member-only types requested by a guest.
     */
    protected function listType(): string
    {
        $configured = $this->config['list_type'] ?? self::DEFAULT_LIST_TYPE;

        $allowed = auth()->check()
            ? [...self::PUBLIC_LIST_TYPES, ...self::MEMBER_LIST_TYPES]
            : self::PUBLIC_LIST_TYPES;

        return in_array($configured, $allowed, true) ? $configured : self::DEFAULT_LIST_TYPE;
    }

    /**
     * Resolve the number of rows to render from widget config.
     *
     * Falls back to a size-aware default when no explicit count is configured.
     */
    protected function itemLimit(): int
    {
        $configured = $this->config['item_count'] ?? null;

        if ($configured !== null && $configured !== '') {
            $limit = (int) $configured;

            // Configs saved before server-side settings cleanup could hold any value.
            if ($limit > 0) {
                return min($limit, self::MAX_ITEMS);
            }
        }

        return $this->isTvSize() ? 10 : 15;
    }

    /**
     * Format time difference in human-readable form.
     *
     * Examples: "5m ago", "1h ago", "2d ago"
     */
    protected function formatTimeAgo(Carbon $time): string
    {
        $diff = $time->diffInSeconds(appNow());

        return match (true) {
            $diff < 60 => 'Just now',
            $diff < 3600 => floor($diff / 60).'m ago',
            $diff < 86400 => floor($diff / 3600).'h ago',
            default => floor($diff / 86400).'d ago',
        };
    }

    /**
     * Get color class for equipment status badge.
     */
    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'delivered' => 'info',
            'committed' => 'warning',
            'cancelled', 'lost', 'damaged' => 'error',
            'returned' => 'neutral',
            default => 'neutral',
        };
    }

    public function render()
    {
        $listType = $this->listType();
        $data = $this->getData();
        $items = $data['items'] ?? [];

        return view('livewire.dashboard.widgets.list-widget', [
            'data' => $data,
            'items' => $items,
            'newItemKeys' => $this->newItemKeys(array_column($items, 'key')),
            'listType' => $listType,
            'size' => $this->size ?? 'normal',
        ]);
    }
}
