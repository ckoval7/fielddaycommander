<?php

namespace App\Events;

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
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('weather');
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
