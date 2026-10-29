<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring;

use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\LimitsPowerByClass;
use App\Scoring\Contracts\RuleSet;

/**
 * The power limit a rulebook sets for each operating class.
 *
 * A rule set implementing {@see LimitsPowerByClass} answers for itself. The
 * frozen rule sets predate that contract, so they get the limits of their
 * rulebook family here instead:
 *
 *  - ARRL Field Day: Classes A, B and C are limited to 500 W PEP. Classes D,
 *    E and F have no limit beyond the licence.
 *  - Winter Field Day: every class is limited to 100 W PEP (WFDA SOP).
 */
final class ClassPowerLimits
{
    /**
     * ARRL Field Day limit for Classes A, B and C, in watts PEP.
     */
    public const FIELD_DAY_PORTABLE_LIMIT_WATTS = 500;

    /**
     * ARRL Field Day classes held to {@see FIELD_DAY_PORTABLE_LIMIT_WATTS}.
     *
     * @var list<string>
     */
    public const FIELD_DAY_LIMITED_CLASSES = ['A', 'B', 'C'];

    /**
     * Winter Field Day limit for every class, in watts PEP.
     */
    public const WINTER_FIELD_DAY_LIMIT_WATTS = 100;

    /**
     * Most power a contact may be made at under the rulebook for the class
     * with this code, or null when the class has no limit.
     */
    public static function for(RuleSet $rules, string $classCode): ?int
    {
        if ($rules instanceof LimitsPowerByClass) {
            return $rules->maxPowerWattsForClass($classCode);
        }

        if ($rules instanceof FieldDayRuleSet) {
            return in_array(strtoupper($classCode), self::FIELD_DAY_LIMITED_CLASSES, true)
                ? self::FIELD_DAY_PORTABLE_LIMIT_WATTS
                : null;
        }

        if ($rules->eventTypeCode() === 'WFD') {
            return self::WINTER_FIELD_DAY_LIMIT_WATTS;
        }

        return null;
    }
}
