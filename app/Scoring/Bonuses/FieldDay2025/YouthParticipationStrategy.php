<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

class YouthParticipationStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'youth_participation';
    }

    public function triggerType(): string
    {
        return 'hybrid';
    }

    public function subscribesTo(): array
    {
        return [QsoLogged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $bonusType = $this->bonusTypeFor($config);

        if (! $bonusType) {
            return;
        }

        $autoCount = $config->countYouthWithQsos();

        $existing = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        $adjustment = $existing && is_numeric($existing->notes) ? (int) $existing->notes : 0;

        $cap = (int) ($bonusType->max_occurrences ?? 5);
        $total = min($autoCount + $adjustment, $cap);

        if ($total <= 0) {
            $this->writeOrDelete($config, $bonusType, null, null);

            return;
        }

        $points = $total * (int) $bonusType->base_points;

        if ($bonusType->max_points !== null) {
            $points = min($points, (int) $bonusType->max_points);
        }

        EventBonus::updateOrCreate(
            [
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $bonusType->id,
            ],
            [
                'quantity' => $total,
                'calculated_points' => $points,
                'notes' => $adjustment > 0 ? (string) $adjustment : null,
                'is_verified' => true,
                'verified_at' => now(),
            ],
        );
    }
}
