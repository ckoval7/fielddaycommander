<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Dto;

/**
 * One clickable figure in the headline score equation.
 *
 * The equation itself is an ordered mix of these and plain operator strings
 * (see {@see ScoreBreakdown::$headline}), so a rulebook can render
 * `(QSO x Power) + Bonus` or `QSO x (OM + 1)` without the view knowing which
 * rulebook it is drawing.
 */
final class ScoreTerm
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly ?string $anchor = null,
    ) {}
}
