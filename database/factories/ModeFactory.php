<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\Mode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mode>
 */
class ModeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(['CW', 'Phone', 'Digital']);

        return [
            // modes.name is unique, so suffix the category to avoid collisions
            // when a test creates several modes or also uses a named state.
            'name' => $category.' '.fake()->unique()->numerify('####'),
            'category' => $category,
            'points_fd' => fake()->randomElement([1, 2]),
            'points_wfd' => fake()->randomElement([1, 2]),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Create a CW mode.
     */
    public function cw(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'CW',
            'category' => 'CW',
            'points_fd' => 2,
            'points_wfd' => 2,
            'description' => 'Continuous Wave (Morse code)',
        ]);
    }

    /**
     * Create a Phone mode.
     */
    public function phone(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Phone',
            'category' => 'Phone',
            'points_fd' => 1,
            'points_wfd' => 1,
            'description' => 'Voice modes (SSB, FM, AM)',
        ]);
    }

    /**
     * Create a Digital mode.
     */
    public function digital(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Digital',
            'category' => 'Digital',
            'points_fd' => 2,
            'points_wfd' => 2,
            'description' => 'Digital modes (FT8, PSK31, RTTY, etc.)',
        ]);
    }
}
