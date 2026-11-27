<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\User;

/**
 * Whether the SYSTEM setup account is locked (SYSTEM_ACCOUNT_LOCKED).
 *
 * A locked SYSTEM can't sign in with a password, is left out of the user
 * list and can't be edited by anyone else. It still opens through a
 * one-time sign-in link (see SignInLinks).
 */
class SystemAccount
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    public static function isLocked(): bool
    {
        return (bool) config('auth-security.system_account_locked', false);
    }

    /**
     * Whether the given account is SYSTEM while it is locked.
     */
    public static function isLockedAccount(?User $user): bool
    {
        return $user !== null && self::isLocked() && $user->isSystemUser();
    }
}
