<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestbookEntry>
 */
class GuestbookEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'callsign' => strtoupper(fake()->bothify('??#???')),
            'email' => fake()->optional(0.7)->email(),
            'comments' => fake()->optional(0.6)->sentence(),
            'presence_type' => fake()->randomElement(PresenceType::cases()),
            'visitor_category' => fake()->randomElement(VisitorCategory::cases()),
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Indicate the entry has been verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate the entry is in-person.
     */
    public function inPerson(): static
    {
        return $this->state(fn (array $attributes) => [
            'presence_type' => PresenceType::InPerson,
        ]);
    }

    /**
     * Indicate the entry is online.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'presence_type' => PresenceType::Online,
        ]);
    }

    /**
     * Indicate the visitor is an elected official.
     */
    public function electedOfficial(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_category' => VisitorCategory::ElectedOfficial,
        ]);
    }

    /**
     * Indicate the visitor is an ARRL official.
     */
    public function arrlOfficial(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_category' => VisitorCategory::ArrlOfficial,
        ]);
    }

    /**
     * Indicate the visitor is from an agency.
     */
    public function agency(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_category' => VisitorCategory::Agency,
        ]);
    }

    /**
     * Indicate the visitor is from the media.
     */
    public function media(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_category' => VisitorCategory::Media,
        ]);
    }

    /**
     * Associate the entry with a user.
     */
    public function withUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => User::factory(),
        ]);
    }
}
