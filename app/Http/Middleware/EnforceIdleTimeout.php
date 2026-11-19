<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\AuthSecurity;
use App\Support\StationMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign out accounts that have made no request for SESSION_TIMEOUT_ADMIN
 * minutes (administrative accounts) or SESSION_TIMEOUT_DEFAULT minutes
 * (everyone else). While an event is in progress or in its setup window and
 * EVENT_DURATION_MODE is on, nobody is signed out for idling, so a station
 * left quiet between contacts stays signed in.
 */
class EnforceIdleTimeout
{
    public const string SESSION_KEY = 'auth.last_activity_at';

    public const string IDLE_REASON = 'idle';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession() || StationMode::isActive()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $lastActivity = $session->get(self::SESSION_KEY);

        if (is_int($lastActivity)
            && $now - $lastActivity > AuthSecurity::idleTimeoutMinutes($user) * 60
            && ! AuthSecurity::idleTimeoutPausedForEvent()) {
            return $this->signOut($request);
        }

        $session->put(self::SESSION_KEY, $now);

        return $next($request);
    }

    /**
     * End the session the same way EnsureAccountNotLocked does: Livewire and
     * JSON requests get a 419 pointing at the login page, others a redirect.
     */
    private function signOut(Request $request): Response
    {
        $loginUrl = route('login', [EnsureAccountNotLocked::REASON_PARAMETER => self::IDLE_REASON]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->isJson() || $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => EnsureAccountNotLocked::messageFor(self::IDLE_REASON),
                'redirect' => $loginUrl,
            ], 419);
        }

        return redirect()->to($loginUrl);
    }
}
