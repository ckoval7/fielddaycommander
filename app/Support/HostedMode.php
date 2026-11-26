<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\User;

class HostedMode
{
    /**
     * Session key marking a session opened through a support sign-in link.
     */
    public const string SUPPORT_SESSION_KEY = 'hosted.support_session';

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Whether this instance is run for a club by a hosting service.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('hosted.enabled', false);
    }

    /**
     * Whether the SYSTEM account is hidden from the club and closed to
     * password sign-in. It stays usable through support sign-in links.
     */
    public static function hidesSystemAccount(): bool
    {
        return self::isEnabled();
    }

    /**
     * Whether the given account is SYSTEM on a hosted instance, and so off
     * limits to the club.
     */
    public static function isHiddenSystemAccount(?User $user): bool
    {
        return $user !== null && self::hidesSystemAccount() && $user->isSystemUser();
    }

    /**
     * Whether support sign-in links are accepted.
     */
    public static function allowsSupportSignIn(): bool
    {
        return self::isEnabled() && filled(config('hosted.support_key'));
    }
}
