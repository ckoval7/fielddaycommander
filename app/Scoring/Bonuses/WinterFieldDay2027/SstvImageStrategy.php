<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2027 objective "sstv_image".
 *
 * OM x2. Sending and receiving the official WFD SSTV image in a logged QSO cannot be told apart from any other Digital contact, so the group claims it.
 */
class SstvImageStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'sstv_image';
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
