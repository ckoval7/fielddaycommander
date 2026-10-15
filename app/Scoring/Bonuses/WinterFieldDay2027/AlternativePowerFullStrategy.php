<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2027 objective "alt_power_full".
 *
 * OM x2. Everything, including lights, HVAC and other appliances sustaining operators, must run on alternative power. Those loads are not modelled, so the group claims it. Achieving it also qualifies for "alt_power_equipment".
 */
class AlternativePowerFullStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'alt_power_full';
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
