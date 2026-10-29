<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\PowerSource;
use App\Enums\StationUnavailableReason;
use App\Models\Equipment;
use App\Models\EventConfiguration;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
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
            'event_configuration_id' => EventConfiguration::factory(),
            'radio_equipment_id' => Equipment::factory()->state(function () {
                return ['type' => 'radio'];
            }),
            'name' => fake()->randomElement($stationNames),
            'power_source' => fake()->randomElement(PowerSource::cases()),
            'power_source_description' => fake()->optional(0.5)->sentence(),
            'is_gota' => false,
            'is_vhf_only' => false,
            'is_satellite' => false,
            'max_power_watts' => 100,
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

    /**
     * Indicate the station has been marked unavailable.
     */
    public function unavailable(StationUnavailableReason $reason = StationUnavailableReason::EquipmentFailure, ?string $note = null): static
    {
        return $this->state(fn (array $attributes) => [
            'unavailable_reason' => $reason,
            'unavailable_note' => $note,
            'unavailable_since' => now(),
        ]);
    }
}
