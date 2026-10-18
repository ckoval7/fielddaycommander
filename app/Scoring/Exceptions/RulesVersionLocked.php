<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Exceptions;

use RuntimeException;

class RulesVersionLocked extends RuntimeException
{
    public static function forEvent(int $eventId): self
    {
        return new self("Cannot change rules_version on event {$eventId}; the event has already started.");
    }
}
