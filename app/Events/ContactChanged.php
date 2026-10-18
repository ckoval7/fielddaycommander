<?php

namespace App\Events;

use App\Models\Contact;
use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open event pages that contacts were edited, deleted or restored in
 * the web UI, so dashboards and score pages refresh without waiting to poll.
 */
class ContactChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $name  BroadcastChannels::CONTACT_UPDATED or CONTACT_DELETED
     * @param  array<int, int>  $contactIds
     */
    public function __construct(
        public int $eventId,
        public string $name,
        public array $contactIds,
    ) {}

    /**
     * Broadcast one change per event the given contacts belong to.
     *
     * @param  iterable<int, Contact>  $contacts
     */
    public static function notify(string $name, iterable $contacts): void
    {
        Collection::make($contacts)
            ->loadMissing('eventConfiguration:id,event_id')
            ->groupBy(fn (Contact $contact): ?int => $contact->eventConfiguration?->event_id)
            ->each(function ($group, $eventId) use ($name) {
                if ($eventId !== '') {
                    static::dispatch((int) $eventId, $name, $group->pluck('id')->all());
                }
            });
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
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'contact_ids' => $this->contactIds,
        ];
    }
}
