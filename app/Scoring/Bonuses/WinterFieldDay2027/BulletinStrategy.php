<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

/**
 * WFD 2027 objective "bulletin".
 *
 * OM x1. The bulletin copy is submitted as supporting documentation rather than logged, so the objective is claimed by hand.
 */
class BulletinStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'bulletin';
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
