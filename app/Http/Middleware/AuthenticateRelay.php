<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\Relay;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the relay from its bearer token. Relays aren't users, so this
 * doesn't sign anyone in. Controllers read the relay from the request.
 */
class AuthenticateRelay
{
    public const REQUEST_ATTRIBUTE = 'relay';

    public const VERSION_HEADER = 'X-Relay-Version';

    private const LAST_SEEN_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $relay = $token === null ? null : Relay::active()
            ->where('token_hash', Relay::hashSecret($token))
            ->with('eventConfiguration.event')
            ->first();

        if ($relay?->eventConfiguration === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'The relay token is missing or invalid.'], 401);
        }

        $this->recordActivity($relay, $request);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $relay);

        return $next($request);
    }

    /**
     * Relays can send often, so last_seen_at is only written once a minute.
     */
    private function recordActivity(Relay $relay, Request $request): void
    {
        $version = $request->header(self::VERSION_HEADER);

        if (is_string($version) && preg_match('/^[0-9A-Za-z.+_-]{1,32}$/', $version) === 1) {
            $relay->relay_version = $version;
        }

        $stale = $relay->last_seen_at === null
            || $relay->last_seen_at->diffInSeconds(now()) >= self::LAST_SEEN_INTERVAL_SECONDS;

        if ($stale) {
            $relay->last_seen_at = now();
        }

        if ($relay->isDirty()) {
            $relay->saveQuietly();
        }
    }
}
