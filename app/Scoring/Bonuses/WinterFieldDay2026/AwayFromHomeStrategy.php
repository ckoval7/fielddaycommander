<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2026 objective "away_from_home".
 *
 * Whether the site is more than half a mile from the operator's home is not derivable from logged data, so the group claims it.
 */
class AwayFromHomeStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'away_from_home';
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
