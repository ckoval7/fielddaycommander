<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\Credential;
use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCredential>
 */
class UserCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credential' => Credential::Is100,
            'level' => null,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()->toDateString()]);
    }
}
