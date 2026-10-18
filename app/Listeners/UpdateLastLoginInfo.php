<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Request;

class UpdateLastLoginInfo
{
    public function handle(Login $event): void
    {
        $event->user->update([
            'last_login_at' => now(),
            'last_login_ip' => Request::ip(),
        ]);
    }
}
