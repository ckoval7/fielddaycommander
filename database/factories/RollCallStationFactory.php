<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\RollCall;
use App\Models\RollCallStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RollCallStation>
 */
class RollCallStationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roll_call_id' => RollCall::factory(),
            'station_id' => null,
            'name' => 'Aid '.fake()->numberBetween(1, 12),
            'callsign' => null,
            'accounted_at' => null,
            'net_log_entry_id' => null,
        ];
    }

    public function accounted(): static
    {
        return $this->state(fn () => ['accounted_at' => now()]);
    }
}
