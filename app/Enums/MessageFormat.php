<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum MessageFormat: string
{
    case Radiogram = 'radiogram';
    case Ics213 = 'ics213';

    public function label(): string
    {
        return match ($this) {
            self::Radiogram => 'ARRL Radiogram',
            self::Ics213 => 'ICS-213',
        };
    }
}
