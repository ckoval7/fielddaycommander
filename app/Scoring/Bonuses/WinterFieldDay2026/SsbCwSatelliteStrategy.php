<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2026 objective "ssb_cw_satellite".
 *
 * Modes are stored by category (CW/Phone/Digital), so SSB cannot be told apart from other Phone sub-modes. Claimed by hand for the same reason as the FM satellite objective.
 */
class SsbCwSatelliteStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'ssb_cw_satellite';
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
