<?php

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
