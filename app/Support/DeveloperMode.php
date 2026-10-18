<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

class DeveloperMode
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Determine whether developer mode features (developer tools, role and
     * call sign overrides) are available for this request.
     *
     * Developer mode is forced off when APP_ENV=production unless
     * DEVELOPER_MODE_ALLOW_PRODUCTION is explicitly set.
     */
    public static function isEnabled(): bool
    {
        if (! config('developer.enabled', false)) {
            return false;
        }

        return ! app()->isProduction() || (bool) config('developer.allow_in_production', false);
    }

    /**
     * Determine whether the session role and call sign overrides (and the
     * role switcher that sets them) are available for this request.
     *
     * Kept separate from isEnabled() so a deployment can allow role
     * switching without exposing the rest of the developer tools.
     */
    public static function allowsRoleOverride(): bool
    {
        return self::isEnabled();
    }
}
