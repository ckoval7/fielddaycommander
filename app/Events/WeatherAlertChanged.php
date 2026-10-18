<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Events;

use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WeatherAlertChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly array $alerts,
        public readonly bool $hasAlerts,
        public readonly bool $manual,
        public readonly bool $shared = false,
    ) {}

    /**
     * Shared alerts (the demo's weather feed) reach every visitor; the rest
     * stay on the visitor's own weather channel.
     */
    public function broadcastOn(): Channel
    {
        return new Channel($this->shared ? BroadcastChannels::sharedWeather() : BroadcastChannels::weather());
    }

    public function broadcastWith(): array
    {
        return [
            'alerts' => $this->alerts,
            'has_alerts' => $this->hasAlerts,
            'manual' => $this->manual,
        ];
    }
}
