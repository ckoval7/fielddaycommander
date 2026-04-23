<?php

namespace App\Scoring\Bonuses;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Contracts\BonusStrategy;

abstract class AbstractBonusStrategy implements BonusStrategy
{
    /**
     * Resolve the BonusType row for this strategy's code against the config's
     * event. Returns null when the code is not defined for this rules_version.
     */
    protected function bonusTypeFor(EventConfiguration $config): ?BonusType
    {
        $event = $config->event;

        if (! $event) {
            return null;
        }

        return BonusType::resolveFor($event, $this->code());
    }

    /**
     * Upsert or delete the EventBonus row to match the supplied quantity/points.
     * No-op when $bonusType is null (bonus code not defined this year).
     * Deletes the row when $quantity is null or zero.
     */
    protected function writeOrDelete(
        EventConfiguration $config,
        ?BonusType $bonusType,
        ?int $quantity,
        ?int $points,
    ): void {
        if (! $bonusType) {
            return;
        }

        $query = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusType->id);

        if ($quantity === null || $quantity <= 0) {
            $query->delete();

            return;
        }

        $existing = $query->first();

        if ($existing) {
            $existing->quantity = $quantity;
            $existing->calculated_points = (int) $points;
            $existing->is_verified = true;
            $existing->save();

            return;
        }

        EventBonus::create([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $bonusType->id,
            'quantity' => $quantity,
            'calculated_points' => (int) $points,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }
}
