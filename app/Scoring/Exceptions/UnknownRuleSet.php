<?php

namespace App\Scoring\Exceptions;

use RuntimeException;

class UnknownRuleSet extends RuntimeException
{
    public static function for(string $eventTypeCode, string $version): self
    {
        return new self(
            "No RuleSet registered for event type '{$eventTypeCode}' version '{$version}'."
        );
    }
}
