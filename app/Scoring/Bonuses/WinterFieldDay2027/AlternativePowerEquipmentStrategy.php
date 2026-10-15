<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2027 objective "alt_power_equipment".
 *
 * OM x1. Every piece of station equipment (laptops, networking, accessories) must run on alternative power for the whole operating period. Station power sources do not cover laptops or networking gear, so the group claims it. Achieving "alt_power_full" also achieves this; the ruleset applies that, see WinterFieldDay2027::impliedObjectives().
 */
class AlternativePowerEquipmentStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'alt_power_equipment';
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
