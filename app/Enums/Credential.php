<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * Emergency communications credentials a volunteer can record on their
 * profile. FD Commander keeps a record of what someone holds; it doesn't
 * track training.
 */
enum Credential: string
{
    case Is100 = 'is_100';
    case Is200 = 'is_200';
    case Is700 = 'is_700';
    case Is800 = 'is_800';
    case Auxcomm = 'auxcomm';
    case Coml = 'coml';
    case Ares = 'ares';

    /**
     * The short name used on the profile and on ICS forms.
     */
    public function label(): string
    {
        return match ($this) {
            self::Is100 => 'IS-100',
            self::Is200 => 'IS-200',
            self::Is700 => 'IS-700',
            self::Is800 => 'IS-800',
            self::Auxcomm => 'AUXCOMM',
            self::Coml => 'COML',
            self::Ares => 'ARES',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Is100 => 'Introduction to the Incident Command System',
            self::Is200 => 'Basic Incident Command System for Initial Response',
            self::Is700 => 'An Introduction to the National Incident Management System',
            self::Is800 => 'National Response Framework, An Introduction',
            self::Auxcomm => 'Auxiliary Communicator',
            self::Coml => 'Communications Unit Leader',
            self::Ares => 'Amateur Radio Emergency Service',
        };
    }

    /**
     * Levels a credential is held at, when it has them.
     *
     * @return list<string>
     */
    public function levels(): array
    {
        return match ($this) {
            self::Ares => ['Level 1', 'Level 2', 'Level 3'],
            default => [],
        };
    }
}
