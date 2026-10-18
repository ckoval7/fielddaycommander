<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

/**
 * WFD 2026 objective "multiple_modes". OM x2.
 *
 * SOP: "Increase your versatility by using multiple modes during the event,
 * such as Phone and CW, CW and Digital, or Phone and Digital. Using all three
 * modes does not increase this OM." Two distinct mode groups is the threshold,
 * and a third earns nothing further.
 */
class MultipleModesStrategy extends AbstractBonusStrategy
{
    /** Distinct mode groups (CW / Phone / Digital) needed to achieve this. */
    private const REQUIRED_MODE_GROUPS = 2;

    public function code(): string
    {
        return 'multiple_modes';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [QsoLogged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $modeGroups = $config->contacts()
            ->where('is_duplicate', false)
            ->whereNotNull('mode_id')
            ->with('mode:id,category')
            ->get()
            ->pluck('mode.category')
            ->filter()
            ->unique()
            ->count();

        $achieved = $modeGroups >= self::REQUIRED_MODE_GROUPS;

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }
}
