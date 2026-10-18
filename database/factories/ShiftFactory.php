<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventConfiguration = EventConfiguration::factory()->create();
        $startTime = fake()->dateTimeBetween('+1 hour', '+24 hours');
        $endTime = (clone $startTime)->modify('+2 hours');

        return [
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => ShiftRole::factory()->state([
                'event_configuration_id' => $eventConfiguration->id,
            ]),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'capacity' => 1,
            'is_open' => false,
            'notes' => null,
        ];
    }

    /**
     * Indicate this shift is open for self-signup.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open' => true,
        ]);
    }

    /**
     * Set a specific capacity for the shift.
     */
    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => $capacity,
        ]);
    }

    /**
     * Associate the shift with a specific event configuration.
     */
    public function forEventConfiguration(EventConfiguration $eventConfiguration): static
    {
        return $this->state(fn (array $attributes) => [
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => ShiftRole::factory()->state([
                'event_configuration_id' => $eventConfiguration->id,
            ]),
        ]);
    }
}
