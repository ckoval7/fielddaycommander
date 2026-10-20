<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\Net;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Net>
 */
class NetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Local 2m Net', 'Regional HF Net', 'Logistics Net', 'Medical Net']),
            'frequency' => null,
            'band_id' => null,
            'mode_id' => null,
            'net_control_station' => 'NCS',
        ];
    }
}
