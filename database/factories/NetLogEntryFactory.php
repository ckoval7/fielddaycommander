<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\NetPriority;
use App\Models\EventConfiguration;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetLogEntry>
 */
class NetLogEntryFactory extends Factory
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
            'net_id' => fn (array $attributes) => Net::factory()->state(['event_configuration_id' => $attributes['event_configuration_id']]),
            'user_id' => User::factory(),
            'logged_at' => now(),
            'from_station' => 'Aid '.fake()->numberBetween(1, 12),
            'to_station' => 'Net Control',
            'from_callsign' => null,
            'to_callsign' => null,
            'message' => fake()->sentence(),
            'priority' => NetPriority::Routine,
        ];
    }

    public function emergency(): static
    {
        return $this->state(fn () => ['priority' => NetPriority::Emergency]);
    }
}
