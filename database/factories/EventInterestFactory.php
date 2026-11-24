<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\InterestAvailability;
use App\Enums\LicenseClass;
use App\Models\EventConfiguration;
use App\Models\EventInterest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventInterest>
 */
class EventInterestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'callsign' => strtoupper(fake()->bothify('??#???')),
            'email' => fake()->unique()->safeEmail(),
            'license_class' => fake()->randomElement(LicenseClass::cases()),
            'availability' => fake()->randomElements(InterestAvailability::cases(), fake()->numberBetween(1, 3)),
            'notes' => fake()->optional(0.4)->sentence(),
            'ip_address' => fake()->ipv4(),
        ];
    }
}
