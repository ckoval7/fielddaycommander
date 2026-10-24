<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * What one run of a checklist covers.
 */
enum ChecklistScope: string
{
    case Event = 'event';
    case Station = 'station';
    case Person = 'person';

    /**
     * Get the human-readable label for the scope.
     */
    public function label(): string
    {
        return match ($this) {
            self::Event => 'Once for the event',
            self::Station => 'Once per station',
            self::Person => 'Once per person',
        };
    }
}
