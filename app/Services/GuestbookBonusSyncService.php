<?php

namespace App\Services;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;

class GuestbookBonusSyncService
{
    private const CATEGORY_BONUS_MAP = [
        GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL => 'elected_official_visit',
        GuestbookEntry::VISITOR_CATEGORY_AGENCY => 'agency_visit',
        GuestbookEntry::VISITOR_CATEGORY_MEDIA => 'media_publicity',
    ];

    public function sync(EventConfiguration $eventConfiguration): void
    {
        foreach (self::CATEGORY_BONUS_MAP as $category => $bonusCode) {
            $this->syncCategoryBonus($eventConfiguration, $category, $bonusCode);
        }
    }

    protected function syncCategoryBonus(EventConfiguration $eventConfiguration, string $category, string $bonusCode): void
    {
        // TODO(rules-version): needs rules_version scope
        $bonusType = BonusType::where('code', $bonusCode)->first();
        if (! $bonusType) {
            return;
        }

        $hasVerifiedEntry = $eventConfiguration->guestbookEntries()
            ->where('visitor_category', $category)
            ->where('is_verified', true)
            ->exists();

        if ($hasVerifiedEntry) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $eventConfiguration->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => 1,
                    'calculated_points' => $bonusType->base_points,
                    'is_verified' => true,
                    'verified_at' => now(),
                ]
            );
        } else {
            EventBonus::where('event_configuration_id', $eventConfiguration->id)
                ->where('bonus_type_id', $bonusType->id)
                ->delete();
        }
    }
}
