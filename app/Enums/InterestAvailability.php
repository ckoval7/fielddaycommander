<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * Rough blocks of time someone says they can help, given before any shifts exist.
 */
enum InterestAvailability: string
{
    case Setup = 'setup';
    case Daytime = 'daytime';
    case Evening = 'evening';
    case Overnight = 'overnight';
    case Teardown = 'teardown';

    public function label(): string
    {
        return match ($this) {
            self::Setup => 'Setup',
            self::Daytime => 'Daytime',
            self::Evening => 'Evening',
            self::Overnight => 'Overnight',
            self::Teardown => 'Teardown',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Setup => 'Before the event starts',
            self::Daytime => 'Morning and afternoon',
            self::Evening => 'Until about midnight',
            self::Overnight => 'Midnight to sunrise',
            self::Teardown => 'After the event ends',
        };
    }
}
