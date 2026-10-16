<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Enums\VisitorCategory;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\GuestbookEntryChanged;

class ElectedOfficialVisitStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'elected_official_visit';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [GuestbookEntryChanged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $bonusType = $this->bonusTypeFor($config);

        if (! $bonusType) {
            return;
        }

        $qualifies = $config->guestbookEntries()
            ->where('visitor_category', VisitorCategory::ElectedOfficial)
            ->where('is_verified', true)
            ->exists();

        $this->writeOrDelete(
            $config,
            $bonusType,
            quantity: $qualifies ? 1 : null,
            points: $qualifies ? (int) $bonusType->base_points : null,
        );
    }
}
