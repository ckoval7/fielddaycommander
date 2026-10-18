<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2026 objective "six_continuous_hours".
 *
 * The rule counts time spent monitoring at the radio, not just contacts logged, so a gap in the log does not disprove it. Claimed by hand.
 */
class SixContinuousHoursStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'six_continuous_hours';
    }

    public function triggerType(): string
    {
        return 'manual';
    }

    public function subscribesTo(): array
    {
        return [];
    }

    public function reconcile(EventConfiguration $config): void
    {
        // Manual objective — the UI writes the claim row directly.
    }
}
