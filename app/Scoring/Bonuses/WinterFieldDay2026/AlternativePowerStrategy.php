<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Enums\PowerSource;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

/**
 * WFD 2026 objective "alternative_power". OM x1.
 *
 * SOP: "Operate exclusively on alternative power, defined as any power source
 * not connected to the commercial power grid. You may use generators,
 * batteries, solar power, wind power, or anything else."
 *
 * Note this differs from the ARRL Field Day natural-power bonus: a generator
 * counts as alternative power here. Lights and HVAC are explicitly exempt, and
 * are not modelled as station power sources, so they do not affect this.
 */
class AlternativePowerStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'alternative_power';
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
        $achieved = ! $config->uses_commercial_power
            && ! $config->stations()
                ->where('power_source', PowerSource::CommercialMains->value)
                ->exists();

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }
}
