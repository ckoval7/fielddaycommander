<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Relay\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateRelay;
use App\Models\Relay;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells a relay what this server supports, so an outdated relay can say so
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
     * Oldest relay this server works with. Null while every version does.
     */
    private const MINIMUM_RELAY_VERSION = null;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Relay $relay */
        $relay = $request->attributes->get(AuthenticateRelay::REQUEST_ATTRIBUTE);
        $event = $relay->eventConfiguration->event;

        return response()->json([
            'server_version' => config('app.version'),
            'supported_api_versions' => self::SUPPORTED_API_VERSIONS,
            'minimum_relay_version' => self::MINIMUM_RELAY_VERSION,
            'server_time' => now()->utc()->toIso8601ZuluString(),
            'relay' => ['id' => $relay->id, 'name' => $relay->name],
            'event' => $event === null ? null : [
                'name' => $event->name,
                'starts_at' => $event->start_time->utc()->toIso8601ZuluString(),
                'ends_at' => $event->operatingEndsAt()->utc()->toIso8601ZuluString(),
            ],
            'permissions' => ['create_stations' => $relay->canCreateStations()],
            'stations' => Station::query()
                ->where('event_configuration_id', $relay->event_configuration_id)
                ->orderBy('name')
                ->get(['name', 'hostname'])
                ->map(fn (Station $station): array => ['name' => $station->name, 'hostname' => $station->hostname])
                ->all(),
        ]);
    }
}
