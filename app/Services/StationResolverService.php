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
        $identifier = $this->normalize($identifier);

        return $this->find($identifier, $eventConfigurationId) ?? Station::create([
            'event_configuration_id' => $eventConfigurationId,
            'name' => $identifier,
            'hostname' => $identifier,
        ]);
    }

    /**
     * Find an existing station by name, then hostname (exact, case-insensitive).
     */
    public function find(string $identifier, int $eventConfigurationId): ?Station
    {
        $identifier = $this->normalize($identifier);

        return Station::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereRaw('LOWER(name) = ?', [strtolower($identifier)])
            ->first()
            ?? Station::query()
                ->where('event_configuration_id', $eventConfigurationId)
                ->whereNotNull('hostname')
                ->whereRaw('LOWER(hostname) = ?', [strtolower($identifier)])
                ->first();
    }

    private function normalize(string $identifier): string
    {
        // Station names and hostnames are 50 wide; a longer name from a
        // logger is matched and stored by its first 50 characters.
        return mb_substr(trim($identifier), 0, self::NAME_MAX_LENGTH);
    }
}
