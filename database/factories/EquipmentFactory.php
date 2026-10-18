<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'make' => ucfirst(fake()->word()),
            'model' => ucfirst(fake()->word()),
            'type' => fake()->randomElement(Equipment::typeKeys()),
            'description' => fake()->sentence(),
            'serial_number' => fake()->unique()->bothify('??-####-??'),
            'emergency_contact_phone' => fake()->phoneNumber(),
            'tags' => fake()->randomElements(['portable', 'QRP', 'digital', 'SSB', 'CW', 'heavy'], fake()->numberBetween(1, 3)),
            'value_usd' => fake()->optional()->randomFloat(2, 100, 5000),
            'notes' => fake()->optional()->paragraph(),
            'power_output_watts' => fake()->optional()->numberBetween(5, 1500),
        ];
    }

    /**
     * Indicate the equipment is owned by an organization.
     */
    public function organizationOwned(): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => null,
            'owner_organization_id' => Organization::factory(),
        ]);
    }

    /**
     * Indicate the equipment has bands attached.
     *
     * @param  array<int>  $bandIds
     */
    public function withBands(array $bandIds): static
    {
        return $this->afterCreating(function (Equipment $equipment) use ($bandIds) {
            $equipment->bands()->attach($bandIds);
        });
    }
}
