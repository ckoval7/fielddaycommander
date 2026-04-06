<?php

namespace App\Events;

use App\Models\Contact;
use App\Models\Event;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactLogged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Contact $contact,
        public Event $event
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("event.{$this->event->id}"),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'contact_id' => $this->contact->id,
            'callsign' => $this->contact->callsign,
            'band' => $this->contact->band->name,
            'mode' => $this->contact->mode->name,
            'section' => $this->contact->section?->code,
            'points' => $this->contact->points,
            'is_duplicate' => $this->contact->is_duplicate,
            'timestamp' => $this->contact->qso_time->toISOString(),
            'qso_count' => Contact::query()
                ->where('event_configuration_id', $this->contact->event_configuration_id)
                ->count(),
        ];
    }
}
