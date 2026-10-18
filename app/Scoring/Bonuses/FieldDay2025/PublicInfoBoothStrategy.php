<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\FieldDay2025;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

class PublicInfoBoothStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'public_info_booth';
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
