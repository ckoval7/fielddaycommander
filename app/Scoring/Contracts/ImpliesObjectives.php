<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Contracts;

/**
 * A rulebook in which achieving one objective also achieves others.
 *
 * WFDA 2027, for example, says operating 100% on alternative power "qualifies
 * you for" the station-equipment alternative power objective. The ruleset
 * applies this when composing the score; the scoring UI reads the same map so
 * its objective checklist agrees with the multiplier it displays.
 */
interface ImpliesObjectives
{
    /**
     * Objective codes that, once achieved, also achieve the listed codes.
     *
     * @return array<string, array<int, string>>
     */
    public function impliedObjectives(): array;
}
