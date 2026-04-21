<?php

namespace App\Observers;

use App\Models\Event;
use App\Scoring\Exceptions\RulesVersionLocked;

class EventObserver
{
    public function creating(Event $event): void
    {
        if ($event->rules_version === null && $event->year !== null) {
            $event->rules_version = (string) $event->year;
        }
    }

    public function updating(Event $event): void
    {
        if (! $event->isDirty('rules_version')) {
            return;
        }

        $isLocked = $event->start_time !== null && $event->start_time <= now();
        $hasContacts = $event->eventConfiguration?->contacts()->exists() ?? false;

        if ($isLocked || $hasContacts) {
            throw RulesVersionLocked::forEvent($event->id);
        }
    }
}
