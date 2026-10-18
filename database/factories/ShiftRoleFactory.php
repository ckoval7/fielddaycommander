<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\ShiftRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftRole>
 */
class ShiftRoleFactory extends Factory
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
            'name' => fake()->randomElement([
                'Station Operator',
                'GOTA Coach',
                'Logger',
                'Setup / Teardown',
                'General Volunteer',
            ]),
            'description' => fake()->sentence(),
            'is_default' => false,
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-user-group',
            'color' => fake()->hexColor(),
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate this is a default role.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Indicate this role awards bonus points.
     */
    public function withBonus(int $points = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'bonus_points' => $points,
            'requires_confirmation' => true,
        ]);
    }

    /**
     * Create a Safety Officer role.
     */
    public function safetyOfficer(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Safety Officer',
            'description' => 'Designated safety officer for the event',
            'is_default' => true,
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'icon' => 'o-shield-check',
            'color' => '#dc2626',
        ]);
    }
}
