<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * When a checklist run counts as complete, which is also when it earns its bonus.
 */
enum ChecklistCompletionRule: string
{
    case RequiredItems = 'required_items';
    case AllItems = 'all_items';
    case RequiredAndMajority = 'required_and_majority';

    /**
     * Get the human-readable label for the rule.
     */
    public function label(): string
    {
        return match ($this) {
            self::RequiredItems => 'All required items (all items if none are required)',
            self::AllItems => 'All items',
            self::RequiredAndMajority => 'All required items and more than half of all items',
        };
    }

    /**
     * Whether a run with these counts meets the rule.
     */
    public function isMet(int $total, int $answered, int $requiredTotal, int $requiredAnswered): bool
    {
        if ($total === 0) {
            return false;
        }

        return match ($this) {
            self::RequiredItems => $requiredAnswered === $requiredTotal && ($requiredTotal > 0 || $answered === $total),
            self::AllItems => $answered === $total,
            self::RequiredAndMajority => $requiredAnswered === $requiredTotal && $answered > $total / 2,
        };
    }
}
