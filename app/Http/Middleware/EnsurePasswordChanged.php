<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\StationMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Redirect users who must change their password before accessing the app.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()
            && $request->user()->requires_password_change
            && ! StationMode::isActive()
            && ! $request->routeIs('profile')
            && ! $request->routeIs('logout', 'logout.get')
            && ! $request->ajax()
            && ! $request->wantsJson()
            && $request->isMethod('GET')
        ) {
            return redirect()->route('profile', ['tab' => 'security']);
        }

        return $next($request);
    }
}
