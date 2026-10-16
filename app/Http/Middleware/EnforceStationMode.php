<?php

namespace App\Http\Middleware;

use App\Support\StationMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceStationMode
{
    /**
     * Keep enrolled browsers on the station-mode landing page and confine
     * station-mode operators to the logging and read-only pages.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has(StationMode::SESSION_KEY)) {
            return $this->handleOperatorSession($request, $next);
        }

        if (! $request->user() && StationMode::deviceFromCookie($request) && ! $request->routeIs(...StationMode::KIOSK_GUEST_ROUTES)) {
            return redirect()->route('station-mode.landing');
        }

        return $next($request);
    }

    /**
     * Handle a request from an operator signed in through station mode.
     */
    private function handleOperatorSession(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            $request->session()->forget(StationMode::SESSION_KEY);

            return $next($request);
        }

        $device = StationMode::currentDevice();

        if (! $device || $device->isRevoked()) {
            StationMode::endSession($request);

            return redirect()->route('login');
        }

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        if (! $request->routeIs(...StationMode::ALLOWED_ROUTES)) {
            abort(403, 'This page is not available in station mode.');
        }

        return $next($request);
    }
}
