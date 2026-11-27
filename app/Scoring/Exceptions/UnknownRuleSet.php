<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

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
