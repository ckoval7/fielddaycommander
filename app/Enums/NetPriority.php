<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * How urgently net control should handle a net log entry.
 */
enum NetPriority: string
{
    case Routine = 'routine';
    case Priority = 'priority';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Priority => 'Priority',
            self::Emergency => 'Emergency',
        };
    }

    /**
     * daisyUI colour for this priority's badge and button. Routine traffic
     * has none and shows no badge.
     */
    public function tone(): ?string
    {
        return match ($this) {
            self::Routine => null,
            self::Priority => 'warning',
            self::Emergency => 'error',
        };
    }

    public function icon(): ?string
    {
        return match ($this) {
            self::Routine => null,
            self::Priority => 'phosphor-warning',
            self::Emergency => 'phosphor-siren',
        };
    }
}
