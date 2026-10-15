<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2027 objective "crossband_repeater".
 *
 * OM x3. Cross-band repeater contacts must not be logged for WFD, so there is nothing in the log to derive this from. Claimed by hand.
 */
class CrossbandRepeaterStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'crossband_repeater';
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
