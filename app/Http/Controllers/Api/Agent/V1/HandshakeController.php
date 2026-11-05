<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Models\Agent;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells an agent what this server supports, so an outdated agent can say so
 * clearly, which event its contacts go to, and what its station picker can
 * offer.
 */
class HandshakeController extends Controller
{
    /**
     * @var list<int>
     */
    private const SUPPORTED_API_VERSIONS = [1];

    /**
     * Oldest agent this server works with. Null while every version does.
     */
    private const MINIMUM_AGENT_VERSION = null;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->attributes->get(AuthenticateAgent::REQUEST_ATTRIBUTE);
        $event = $agent->eventConfiguration->event;

        return response()->json([
            'server_version' => config('app.version'),
            'supported_api_versions' => self::SUPPORTED_API_VERSIONS,
            'minimum_agent_version' => self::MINIMUM_AGENT_VERSION,
            'server_time' => now()->utc()->toIso8601ZuluString(),
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'event' => $event === null ? null : [
                'name' => $event->name,
                'starts_at' => $event->start_time->utc()->toIso8601ZuluString(),
                'ends_at' => $event->operatingEndsAt()->utc()->toIso8601ZuluString(),
            ],
            'permissions' => ['create_stations' => $agent->canCreateStations()],
            'stations' => Station::query()
                ->where('event_configuration_id', $agent->event_configuration_id)
                ->orderBy('name')
                ->get(['name', 'hostname'])
                ->map(fn (Station $station): array => ['name' => $station->name, 'hostname' => $station->hostname])
                ->all(),
        ]);
    }
}
