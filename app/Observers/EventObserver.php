<?php

namespace App\Observers;

use App\Models\Event;
use App\Scoring\Exceptions\RulesVersionLocked;

class EventObserver
{
    private static bool $bypassRulesVersionLock = false;

    /**
     * Run $callback with the rules_version lock disabled. Restores the previous
     * state in a `finally` so no leakage into subsequent Octane requests.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRulesVersionLock(callable $callback): mixed
    {
        $previous = self::$bypassRulesVersionLock;
        self::$bypassRulesVersionLock = true;

        try {
            return $callback();
        } finally {
            self::$bypassRulesVersionLock = $previous;
        }
    }

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
