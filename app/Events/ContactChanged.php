<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
     * A broadcaster failure is reported but never thrown: with the sync queue
     * (as on the demo) the broadcast runs inline, and a Reverb outage must not
     * fail the edit or delete that already saved.
     *
     * @param  iterable<int, Contact>  $contacts
     */
    public static function notify(string $name, iterable $contacts): void
    {
        Collection::make($contacts)
            ->loadMissing('eventConfiguration:id,event_id')
            ->groupBy(fn (Contact $contact): ?int => $contact->eventConfiguration?->event_id)
            ->each(function ($group, $eventId) use ($name) {
                if ($eventId === '') {
                    return;
                }

                try {
                    static::dispatch((int) $eventId, $name, $group->pluck('id')->all());
                } catch (\Throwable $e) {
                    report($e);
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
