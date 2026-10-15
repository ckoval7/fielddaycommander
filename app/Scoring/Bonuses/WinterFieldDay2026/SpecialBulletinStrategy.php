<?php

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\W1awBulletin;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\W1awBulletinChanged;

/**
 * WFD 2026 objective "special_bulletin". OM x1.
 *
 * Earned by saving the copied bulletin on the bulletin page, which is also
 * the copy submitted with the log. Claims made by hand before this objective
 * was derived are left alone when no bulletin has been saved.
 */
class SpecialBulletinStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'special_bulletin';
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

        if (W1awBulletin::where('event_configuration_id', $config->id)->exists()) {
            $this->writeOrDelete($config, $bonusType, quantity: 1, points: 0);

            return;
        }

        EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusType->id)
            ->whereNull('claimed_by_user_id')
            ->delete();
    }
}
