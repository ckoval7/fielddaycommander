<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\OperationalPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalPeriod>
 */
class OperationalPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = appNow()->startOfHour();

        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'name' => 'Day '.fake()->numberBetween(1, 3).' '.fake()->randomElement(['AM', 'PM']),
            'start_time' => $start,
            'end_time' => $start->copy()->addHours(8),
        ];
    }
}
