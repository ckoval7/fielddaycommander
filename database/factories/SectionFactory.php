<?php

namespace Database\Factories;

use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // No real ARRL/RAC section starts with Z, so this can't collide with seeded sections
            'code' => 'Z'.strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->state(),
            'region' => fake()->randomElement(['W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0', 'KL7', 'VE', 'DX']),
            'country' => fake()->randomElement(['US', 'CA', 'MX']),
            'is_active' => true,
        ];
    }
}
