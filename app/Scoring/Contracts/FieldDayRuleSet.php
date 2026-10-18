<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Contracts;

use App\Scoring\Dto\PowerContext;

/**
 * Rules specific to the ARRL Field Day family of rulebooks.
 *
 * These concepts — a power multiplier, a GOTA station, the youth participation
 * formula, the per-transmitter emergency power cap — exist only in ARRL Field
 * Day. Rulebooks built on a different model (Winter Field Day scores QSO points
 * against an objective multiplier and has no GOTA station at all) implement
 * {@see RuleSet} directly rather than stubbing methods that mean nothing to them.
 */
interface FieldDayRuleSet extends RuleSet
{
    /**
     * Flat points for each non-duplicate GOTA contact, ignored by QSO multiplier.
     */
    public function gotaPointsPerContact(): int;

    /**
     * Returns '1', '2', or '5' (string, to match stored `power_multiplier`).
     */
    public function powerMultiplier(PowerContext $ctx): string;

    public function gotaCoachThreshold(): int;

    public function gotaCoachBonus(): int;

    public function youthMaxCount(): int;

    public function youthPointsPerYouth(): int;

    /**
     * Max number of transmitters eligible for the emergency-power bonus.
     */
    public function emergencyPowerMaxTransmitters(): int;
}
