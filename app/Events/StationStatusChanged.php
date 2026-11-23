<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Events;

use App\Models\Station;
use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open event pages that a station's operator, band, mode or readiness
 * changed, so the stations page refreshes without a reload.
 */
class StationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $eventId,
        public int $stationId,
    ) {}

    /**
     * Broadcast a change for the given station, if it belongs to an event.
     */
    public static function notify(Station|int|null $station): void
    {
        if (! $station instanceof Station) {
            $station = $station === null ? null : Station::withTrashed()->find($station);
        }

        $eventId = $station?->eventConfiguration()->value('event_id');

        if ($eventId !== null) {
            static::dispatch((int) $eventId, $station->id);
        }
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(BroadcastChannels::event($this->eventId)),
        ];
    }

    public function broadcastAs(): string
    {
        return BroadcastChannels::STATION_STATUS_CHANGED;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->stationId,
        ];
    }
}
