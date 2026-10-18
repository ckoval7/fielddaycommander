<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Dto;

/**
 * One labelled figure in a {@see ScoreBreakdown}.
 *
 * Rulesets emit these so callers can read a named part of the score — QSO
 * points, an objective multiplier — without knowing which rulebook produced
 * it. How the score is *displayed* is carried separately by
 * {@see ScoreBreakdown::$headline}.
 */
final class ScoreLine
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $value,
        public readonly ?string $detail = null,
    ) {}
}
