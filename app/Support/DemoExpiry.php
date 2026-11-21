<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * When the current demo sandbox is cleaned up: its provisioning time plus the demo TTL.
 */
class DemoExpiry
{
    public static function at(): ?Carbon
    {
        $provisionedAt = Setting::get('demo_provisioned_at');

        if (! $provisionedAt) {
            return null;
        }

        return Carbon::parse($provisionedAt)->addHours(config('demo.ttl_hours', 24));
    }
}
