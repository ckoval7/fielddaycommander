<?php

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

class WebSubmissionStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'web_submission';
    }

    public function triggerType(): string
    {
        return 'manual';
    }

    public function subscribesTo(): array
    {
        return [];
    }

    public function reconcile(EventConfiguration $config): void
    {
        // Manual bonus — UI writes the row directly.
    }
}
