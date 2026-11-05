<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the agent from its bearer token. Agents aren't users, so this
 * doesn't sign anyone in. Controllers read the agent from the request.
 */
class AuthenticateAgent
{
    public const REQUEST_ATTRIBUTE = 'agent';

    public const VERSION_HEADER = 'X-Agent-Version';

    private const LAST_SEEN_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $agent = $token === null ? null : Agent::active()
            ->where('token_hash', Agent::hashSecret($token))
            ->with('eventConfiguration.event')
            ->first();

        if ($agent?->eventConfiguration === null) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'The agent token is missing or invalid.'], 401);
        }

        $this->recordActivity($agent, $request);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $agent);

        return $next($request);
    }

    /**
     * Agents can send often, so last_seen_at is only written once a minute.
     */
    private function recordActivity(Agent $agent, Request $request): void
    {
        $version = $request->header(self::VERSION_HEADER);

        if (is_string($version) && preg_match('/^[0-9A-Za-z.+_-]{1,32}$/', $version) === 1) {
            $agent->agent_version = $version;
        }

        $stale = $agent->last_seen_at === null
            || $agent->last_seen_at->diffInSeconds(now()) >= self::LAST_SEEN_INTERVAL_SECONDS;

        if ($stale) {
            $agent->last_seen_at = now();
        }

        if ($agent->isDirty()) {
            $agent->saveQuietly();
        }
    }
}
