<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExternalContactUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Contact $contact,
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
            'contact_id' => $this->contact->id,
            'callsign' => $this->contact->callsign,
            'band' => $this->contact->band?->name,
            'mode' => $this->contact->mode?->name,
            'timestamp' => $this->contact->qso_time?->toISOString(),
            'source' => $this->source,
        ];
    }
}
