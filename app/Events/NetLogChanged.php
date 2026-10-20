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

/**
 * Tells open net log pages that an entry was added, edited or deleted, or a
 * net was started or changed (no entry id), so every net control screen
 * shows the same log without polling.
 */
class NetLogChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $eventId,
        public ?int $entryId = null,
    ) {}

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
        return BroadcastChannels::NET_LOG_CHANGED;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'entry_id' => $this->entryId,
        ];
    }
}
