<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Band>
 */
class BandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bands = [
            ['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'is_hf' => true],
            ['name' => '80m', 'meters' => 80, 'frequency_mhz' => 3.5, 'is_hf' => true],
            ['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'is_hf' => true],
            ['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'is_hf' => true],
            ['name' => '15m', 'meters' => 15, 'frequency_mhz' => 21.0, 'is_hf' => true],
            ['name' => '10m', 'meters' => 10, 'frequency_mhz' => 28.0, 'is_hf' => true],
            ['name' => '6m', 'meters' => 6, 'frequency_mhz' => 50.0, 'is_vhf_uhf' => true],
            ['name' => '2m', 'meters' => 2, 'frequency_mhz' => 144.0, 'is_vhf_uhf' => true],
            ['name' => '70cm', 'meters' => 0.7, 'frequency_mhz' => 440.0, 'is_vhf_uhf' => true],
        ];

        $band = fake()->randomElement($bands);

        return [
            'name' => $band['name'],
            'meters' => $band['meters'],
            'frequency_mhz' => $band['frequency_mhz'],
            'is_hf' => $band['is_hf'] ?? false,
            'is_vhf_uhf' => $band['is_vhf_uhf'] ?? false,
            'is_satellite' => false,
            'allowed_fd' => true,
            'allowed_wfd' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
