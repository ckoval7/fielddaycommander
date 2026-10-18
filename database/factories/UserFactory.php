<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_sign' => \App\Support\CallsignGenerator::any(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is a ghost account created without a login.
     */
    public function ghost(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => $attributes['call_sign'],
            'last_name' => '(Imported)',
            'email' => strtolower($attributes['call_sign']).'@imported.local',
            'email_verified_at' => null,
            'password' => Hash::make(Str::random(64)),
            'user_role' => 'locked',
            'is_ghost' => true,
            'account_locked_at' => now(),
            'requires_password_change' => true,
        ]);
    }
}
