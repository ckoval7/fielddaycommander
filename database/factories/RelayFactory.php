<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\Relay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Relay>
 */
class RelayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'name' => fake()->unique()->word().' PC',
            'token_hash' => Relay::hashSecret(Str::random(64)),
            'enrolled_at' => now(),
        ];
    }

    /**
     * A relay holding the given raw token.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => Relay::hashSecret($token)]);
    }

    /**
     * A relay whose enrollment code has been issued but not yet redeemed.
     */
    public function pending(string $code): static
    {
        return $this->state([
            'enrollment_code_hash' => Relay::hashSecret($code),
            'enrollment_expires_at' => now()->addMinutes(15),
            'token_hash' => null,
            'enrolled_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
