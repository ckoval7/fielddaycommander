<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\GuestbookEntryChanged;

class AgencyVisitStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'agency_visit';
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
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_AGENCY)
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
