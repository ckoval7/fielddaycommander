<?php

namespace App\Services;

use App\Models\Station;

class StationResolverService
{
    /**
     * Resolve a station identifier to a FD Commander Station.
     *
     * Match order: name (exact, case-insensitive) -> hostname (exact, case-insensitive) -> auto-create.
     */
    public function resolve(string $identifier, int $eventConfigurationId): Station
    {
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
