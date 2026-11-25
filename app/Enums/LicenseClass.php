<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * License classes a person can report on the interest form. The first four
 * match the values stored on users; Unlicensed only applies to interest.
 */
enum LicenseClass: string
{
    case Unlicensed = 'Unlicensed';
    case Technician = 'Technician';
    case General = 'General';
    case Advanced = 'Advanced';
    case Extra = 'Extra';

    public function label(): string
    {
        return match ($this) {
            self::Unlicensed => 'Not licensed yet',
            default => $this->value,
        };
    }

    /**
     * The value to store on a user account, which has no "unlicensed" option.
     */
    public function userValue(): ?string
    {
        return $this === self::Unlicensed ? null : $this->value;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $class): array => ['value' => $class->value, 'label' => $class->label()],
            self::cases(),
        );
    }
}
