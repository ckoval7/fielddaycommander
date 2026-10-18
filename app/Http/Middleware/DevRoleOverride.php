<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\DeveloperMode;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class DevRoleOverride
{
    /**
     * Handle an incoming request.
     *
     * Force permission cache refresh when dev role override changes, and drop
     * any stale overrides when developer mode is off.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeveloperMode::allowsRoleOverride()) {
            if (session()->has('dev_role_override') || session()->has('dev_callsign_override')) {
                session()->forget(['dev_role_override', 'dev_callsign_override']);
            }

            return $next($request);
        }

        if (auth()->check() && session()->has('dev_role_override')) {
            // Clear Spatie's permission cache to force fresh authorization checks
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }

        return $next($request);
    }
}
