<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mode>
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
        return [
            'name' => fake()->randomElement(['CW', 'Phone', 'Digital']),
            'category' => fake()->randomElement(['CW', 'Phone', 'Digital']),
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
