<?php

namespace App\Scoring\Exceptions;

use RuntimeException;

class RulesVersionLocked extends RuntimeException
{
    public static function forEvent(int $eventId): self
    {
        return new self("Cannot change rules_version on event {$eventId}; the event has already started.");
    }
}
