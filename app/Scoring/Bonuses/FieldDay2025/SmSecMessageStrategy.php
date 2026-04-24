<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\MessageChanged;

class SmSecMessageStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'sm_sec_message';
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

        $qualifies = $config->messages()
            ->where('is_sm_message', true)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('role', 'received_delivered'))
            ->exists();

        $this->writeOrDelete(
            $config,
            $bonusType,
            quantity: $qualifies ? 1 : null,
            points: $qualifies ? (int) $bonusType->base_points : null,
        );
    }
}
