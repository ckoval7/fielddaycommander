<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShiftRole>
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
            'event_configuration_id' => \App\Models\EventConfiguration::factory(),
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
            'color' => 'badge-neutral',
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
            'color' => 'badge-warning',
        ]);
    }
}
