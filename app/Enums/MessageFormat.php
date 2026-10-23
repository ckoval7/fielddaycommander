<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum MessageFormat: string
{
    case Radiogram = 'radiogram';
    case Ics213 = 'ics213';

    public function label(): string
    {
        return match ($this) {
            self::Radiogram => 'Radiogram',
            self::Ics213 => 'ICS-213',
        };
    }
}
