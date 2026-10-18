<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Events;

use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExternalContactDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $contactId,
        public string $callsign,
        public int $eventConfigurationId,
        public string $source,
        public ?string $stationName = null,
        public ?int $eventId = null,
    ) {}

    /**
     * Broadcast on the external logger channel and, when the event is known,
     * on the event channel so open dashboards and score pages refresh.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel(BroadcastChannels::externalLogger($this->eventConfigurationId)),
        ];

        if ($this->eventId !== null) {
            $channels[] = new PrivateChannel(BroadcastChannels::event($this->eventId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return BroadcastChannels::CONTACT_DELETED;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'contact_id' => $this->contactId,
            'callsign' => $this->callsign,
            'station' => $this->stationName,
            'source' => $this->source,
        ];
    }
}
