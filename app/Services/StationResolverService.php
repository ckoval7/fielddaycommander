<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Station;

class StationResolverService
{
    /**
     * Width of stations.name and stations.hostname.
     */
    private const NAME_MAX_LENGTH = 50;

    /**
     * Resolve a station identifier to a Field Day Commander Station.
     *
     * Match order: name (exact, case-insensitive) -> hostname (exact, case-insensitive) -> auto-create.
     */
    public function resolve(string $identifier, int $eventConfigurationId): Station
    {
        // Station names and hostnames are 50 wide; a longer name from a
        // logger is matched and stored by its first 50 characters.
        $identifier = mb_substr(trim($identifier), 0, self::NAME_MAX_LENGTH);

        // Try name match first
        $station = Station::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereRaw('LOWER(name) = ?', [strtolower($identifier)])
            ->first();

        if ($station !== null) {
            return $station;
        }

        // Try hostname match
        $station = Station::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereNotNull('hostname')
            ->whereRaw('LOWER(hostname) = ?', [strtolower($identifier)])
            ->first();

        if ($station !== null) {
            return $station;
        }

        // Auto-create
        return Station::create([
            'event_configuration_id' => $eventConfigurationId,
            'name' => $identifier,
            'hostname' => $identifier,
        ]);
    }
}
