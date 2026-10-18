<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2026 objective "fm_satellite".
 *
 * Modes are stored by category (CW/Phone/Digital), so an FM satellite QSO is indistinguishable from an SSB one. Deriving this would wrongly award the objective to SSB-only satellite operations, so it is claimed by hand.
 */
class FmSatelliteStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'fm_satellite';
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
