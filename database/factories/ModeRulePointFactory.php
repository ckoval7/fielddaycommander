<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModeRulePoint>
 */
class ModeRulePointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'rules_version' => '2025',
            'mode_id' => Mode::factory(),
            'points' => 2,
        ];
    }
}
