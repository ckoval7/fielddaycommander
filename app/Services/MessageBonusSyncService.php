<?php

namespace App\Services;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\W1awBulletin;

class MessageBonusSyncService
{
    public function sync(EventConfiguration $eventConfiguration): void
    {
        $this->syncSmSecBonus($eventConfiguration);
        $this->syncMessageHandlingBonus($eventConfiguration);
        $this->syncW1awBonus($eventConfiguration);
    }

    protected function syncSmSecBonus(EventConfiguration $eventConfiguration): void
    {
        // TODO(rules-version): needs rules_version scope
        $bonusType = BonusType::where('code', 'sm_sec_message')->first();
        if (! $bonusType) {
            return;
        }

        $hasSmMessage = $eventConfiguration->messages()
            ->where('is_sm_message', true)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('role', 'received_delivered'))
            ->exists();

        if ($hasSmMessage) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $eventConfiguration->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => 1,
                    'calculated_points' => 100,
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

    protected function syncMessageHandlingBonus(EventConfiguration $eventConfiguration): void
    {
        // TODO(rules-version): needs rules_version scope
        $bonusType = BonusType::where('code', 'nts_message')->first();
        if (! $bonusType) {
            return;
        }

        $count = $eventConfiguration->messages()
            ->where('is_sm_message', false)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('role', 'received_delivered'))
            ->count();

        if ($count > 0) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $eventConfiguration->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => $count,
                    'calculated_points' => min($count, 10) * 10,
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

    public function bonusSummary(EventConfiguration $eventConfiguration): array
    {
        $eligibleMessages = $eventConfiguration->messages()
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('role', 'received_delivered'))
            ->get();
        $hasBulletin = W1awBulletin::where('event_configuration_id', $eventConfiguration->id)->exists();

        $smMessage = $eligibleMessages->where('is_sm_message', true)->first();
        $trafficCount = $eligibleMessages->where('is_sm_message', false)->count();

        return [
            'sm_message' => (bool) $smMessage,
            'sm_points' => $smMessage ? 100 : 0,
            'traffic_count' => $trafficCount,
            'traffic_points' => min($trafficCount, 10) * 10,
            'w1aw_bulletin' => $hasBulletin,
            'w1aw_points' => $hasBulletin ? 100 : 0,
            'total' => ($smMessage ? 100 : 0) + (min($trafficCount, 10) * 10) + ($hasBulletin ? 100 : 0),
        ];
    }

    protected function syncW1awBonus(EventConfiguration $eventConfiguration): void
    {
        // TODO(rules-version): needs rules_version scope
        $bonusType = BonusType::where('code', 'w1aw_bulletin')->first();
        if (! $bonusType) {
            return;
        }

        $hasBulletin = W1awBulletin::where('event_configuration_id', $eventConfiguration->id)->exists();

        if ($hasBulletin) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $eventConfiguration->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => 1,
                    'calculated_points' => 100,
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
