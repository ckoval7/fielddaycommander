<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Station>
 */
class StationFactory extends Factory
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
            'radio_equipment_id' => null,
            'name' => 'Station '.fake()->randomDigit(),
            'power_source_description' => null,
            'is_gota' => false,
            'is_vhf_only' => false,
            'is_satellite' => false,
            'max_power_watts' => 100,
        ];
    }
}
