<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->randomElement(['Field Day', 'Winter Field Day', 'ARRL Field Day']),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * The unscored Public Service / Other event type.
     */
    public function publicService(): static
    {
        return $this->state(fn () => [
            'code' => 'PS',
            'name' => 'Public Service / Other',
            'is_scored' => false,
            'setup_offset_hours' => 0,
        ]);
    }
}
