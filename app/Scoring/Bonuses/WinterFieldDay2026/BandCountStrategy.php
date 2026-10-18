<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

/**
 * Shared logic for the WFD 2026 "three contacts on N different bands" objectives.
 *
 * SOP: "Log operations on at least six different bands by making a minimum of
 * three contacts per band." The twelve-band objective counts the same bands, so
 * both objectives differ only in the threshold.
 *
 * Satellite QSOs are excluded from QSO points but are still contacts on a band,
 * so they count toward the band tally.
 */
abstract class BandCountStrategy extends AbstractBonusStrategy
{
    /** Minimum non-duplicate contacts a band needs before it counts. */
    protected const CONTACTS_PER_BAND = 3;

    /**
     * How many qualifying bands this objective requires.
     */
    abstract protected function requiredBands(): int;

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
        $qualifyingBands = $config->contacts()
            ->where('is_duplicate', false)
            ->whereNotNull('band_id')
            ->selectRaw('band_id')
            ->groupBy('band_id')
            ->havingRaw('COUNT(*) >= ?', [self::CONTACTS_PER_BAND])
            ->get()
            ->count();

        $achieved = $qualifyingBands >= $this->requiredBands();

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }
}
