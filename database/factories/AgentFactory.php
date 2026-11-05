<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\Agent;
use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'name' => fake()->unique()->word().' PC',
            'token_hash' => Agent::hashSecret(Str::random(64)),
            'enrolled_at' => now(),
        ];
    }

    /**
     * An agent holding the given raw token.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => Agent::hashSecret($token)]);
    }

    /**
     * An agent whose enrollment code has been issued but not yet redeemed.
     */
    public function pending(string $code): static
    {
        return $this->state([
            'enrollment_code_hash' => Agent::hashSecret($code),
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
