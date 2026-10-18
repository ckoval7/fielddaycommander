<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserResolverService
{
    public const PLACEHOLDER_LAST_NAME = '(Imported)';

    /** @var array<string, User> */
    private array $cache = [];

    /**
     * Find a user by callsign, or create a locked ghost account.
     *
     * When names are supplied they are used for a new ghost, and replace the
     * placeholder name on an existing ghost that was created without one.
     */
    public function resolveOrCreate(string $callsign, ?string $firstName = null, ?string $lastName = null): User
    {
        if (trim($callsign) === '') {
            throw new \InvalidArgumentException('Callsign cannot be empty.');
        }

        $callsign = strtoupper(trim($callsign));
        $firstName = filled($firstName) ? trim($firstName) : null;
        $lastName = filled($lastName) ? trim($lastName) : null;

        if (isset($this->cache[$callsign]) && $firstName === null) {
            return $this->cache[$callsign];
        }

        $user = User::firstOrCreate(
            ['call_sign' => $callsign],
            [
                'first_name' => $firstName ?? $callsign,
                'last_name' => $lastName ?? self::PLACEHOLDER_LAST_NAME,
                'email' => strtolower($callsign).'@imported.local',
                'password' => Hash::make(Str::random(64)),
                'user_role' => 'locked',
                'is_ghost' => true,
                'account_locked_at' => now(),
                'requires_password_change' => true,
            ]
        );

        if ($firstName !== null && $this->hasPlaceholderName($user)) {
            $user->update([
                'first_name' => $firstName,
                'last_name' => $lastName ?? '',
            ]);
        }

        return $this->cache[$callsign] = $user;
    }

    /**
     * Check whether a ghost account still carries the placeholder import name.
     */
    public function hasPlaceholderName(User $user): bool
    {
        return $user->is_ghost && $user->last_name === self::PLACEHOLDER_LAST_NAME;
    }
}
