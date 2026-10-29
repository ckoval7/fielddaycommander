<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Contracts;

use App\Scoring\ClassPowerLimits;

/**
 * A rulebook that states its own transmitter power limit per operating class.
 *
 * Contacts made above the limit are still logged but score 0 points.
 *
 * Rulesets that do not implement this get the limits of their rulebook family
 * from {@see ClassPowerLimits}: the frozen rule sets predate this contract and
 * must not be edited. A new rules version whose limits differ from its family
 * implements this contract. Callers go through {@see ClassPowerLimits::for()},
 * not this contract directly.
 */
interface LimitsPowerByClass
{
    /**
     * Most power, in watts PEP, a contact may be made at under the operating
     * class with this code, or null when the class has no limit of its own.
     */
    public function maxPowerWattsForClass(string $classCode): ?int;
}
