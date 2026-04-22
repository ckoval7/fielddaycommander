<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Models\W1awBulletin;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\W1awBulletinChanged;

class W1awBulletinStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'w1aw_bulletin';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [W1awBulletinChanged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $bonusType = $this->bonusTypeFor($config);

        if (! $bonusType) {
            return;
        }

        $exists = W1awBulletin::where('event_configuration_id', $config->id)->exists();

        $this->writeOrDelete(
            $config,
            $bonusType,
            quantity: $exists ? 1 : null,
            points: $exists ? (int) $bonusType->base_points : null,
        );
    }
}
