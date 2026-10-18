<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Dto;

/**
 * A fully-composed score, as computed by the event's pinned RuleSet.
 *
 * Each rulebook composes its total differently — ARRL Field Day adds bonus
 * points to a power-multiplied QSO score, while Winter Field Day multiplies
 * QSO points by an objective multiplier. Rather than teach the UI both, every
 * ruleset returns its own ordered line items plus the resulting total.
 */
final class ScoreBreakdown
{
    /**
     * @param  array<int, ScoreLine>  $lines
     * @param  array<int, ScoreTerm|string>  $headline  Ordered equation parts;
     *                                                  plain strings are operators.
     */
    public function __construct(
        public readonly int $total,
        public readonly array $lines,
        public readonly ?string $formula = null,
        public readonly array $headline = [],
    ) {}

    /**
     * Look up a single line by key, or null when this ruleset does not emit it.
     */
    public function line(string $key): ?ScoreLine
    {
        foreach ($this->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Value of the named line, or $default when this ruleset does not emit it.
     */
    public function value(string $key, int $default = 0): int
    {
        return $this->line($key)?->value ?? $default;
    }
}
