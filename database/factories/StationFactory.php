<?php

namespace Database\Factories;

use App\Enums\PowerSource;
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
        $stationNames = [
            'Station 1',
            '20m CW',
            '40m SSB',
            '80m Rig',
            'Digital Station',
            'VHF/UHF Rig',
            'Emergency Net',
        ];

        return [
            'event_configuration_id' => \App\Models\EventConfiguration::factory(),
            'radio_equipment_id' => \App\Models\Equipment::factory()->state(function () {
                return ['type' => 'radio'];
            }),
            'name' => fake()->randomElement($stationNames),
            'power_source' => fake()->randomElement(PowerSource::cases()),
            'power_source_description' => fake()->optional(0.5)->sentence(),
            'is_gota' => false,
            'is_vhf_only' => false,
            'is_satellite' => false,
            'max_power_watts' => fake()->randomElement([5, 100, 150, 500, 1500]),
        ];
    }

    /**
     * Indicate the station is a GOTA (Get On The Air) station.
     */
    public function gota(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_gota' => true,
            'name' => 'GOTA Station',
        ]);
    }

    /**
     * Indicate the station is VHF only.
     */
    public function vhfOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_vhf_only' => true,
            'max_power_watts' => fake()->numberBetween(5, 100),
        ]);
    }

    /**
     * Indicate the station is a satellite station.
     */
    public function satellite(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_satellite' => true,
            'name' => 'Satellite '.fake()->word(),
        ]);
    }
}
