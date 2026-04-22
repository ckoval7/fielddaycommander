<?php

namespace App\Observers;

use App\Models\Event;
use App\Scoring\Exceptions\RulesVersionLocked;

class EventObserver
{
    public static bool $bypassRulesVersionLock = false;

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

        if (self::$bypassRulesVersionLock) {
            return;
        }

        if ($event->start_time !== null && $event->start_time <= now()) {
            throw RulesVersionLocked::forEvent($event->id);
        }
    }
}
