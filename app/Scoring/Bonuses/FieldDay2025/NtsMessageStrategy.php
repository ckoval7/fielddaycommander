<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\MessageChanged;

class NtsMessageStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'nts_message';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [MessageChanged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $bonusType = $this->bonusTypeFor($config);

        if (! $bonusType) {
            return;
        }

        $count = $config->messages()
            ->where('is_sm_message', false)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('role', 'received_delivered'))
            ->count();

        if ($count === 0) {
            $this->writeOrDelete($config, $bonusType, null, null);

            return;
        }

        $quantity = $bonusType->max_occurrences
            ? min($count, (int) $bonusType->max_occurrences)
            : $count;

        $points = $quantity * (int) $bonusType->base_points;

        if ($bonusType->max_points !== null) {
            $points = min($points, (int) $bonusType->max_points);
        }

        $this->writeOrDelete($config, $bonusType, $quantity, $points);
    }
}
