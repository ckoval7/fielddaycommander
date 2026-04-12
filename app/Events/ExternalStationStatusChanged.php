<?php

namespace App\Events;

use App\Models\OperatingSession;
use App\Models\Station;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExternalStationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Station $station,
        public OperatingSession $session,
        public int $eventConfigurationId,
        public string $source,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("event.{$this->eventConfigurationId}.external-logger"),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->station->id,
            'station_name' => $this->station->name,
            'operator' => $this->session->operator?->call_sign,
            'band' => $this->session->band?->name,
            'mode' => $this->session->mode?->name,
            'source' => $this->source,
        ];
    }
}
