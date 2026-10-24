<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * How a checklist item is answered.
 */
enum ChecklistAnswerType: string
{
    case Checkbox = 'checkbox';
    case YesNoNa = 'yes_no_na';
    case Text = 'text';
    case Number = 'number';

    /**
     * Get the human-readable label for the answer type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Checkbox => 'Checkbox',
            self::YesNoNa => 'Yes / No / N/A',
            self::Text => 'Short text',
            self::Number => 'Number',
        };
    }

    /**
     * Normalize a submitted value into what is stored, or null for no answer.
     */
    public function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return match ($this) {
            self::Checkbox => '1',
            self::YesNoNa => in_array($value, ['yes', 'no', 'na'], true) ? $value : null,
            self::Text => mb_substr(trim((string) $value), 0, 500) ?: null,
            self::Number => is_numeric($value) ? (string) (0 + $value) : null,
        };
    }

    /**
     * Get the display text for a stored value.
     */
    public function display(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Checkbox => 'Done',
            self::YesNoNa => ['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A'][$value] ?? $value,
            self::Text, self::Number => $value,
        };
    }
}
