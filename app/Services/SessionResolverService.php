<?php

namespace App\Services;

use App\Models\OperatingSession;
use Illuminate\Support\Carbon;

class SessionResolverService
{
    /**
     * Find or create an OperatingSession for the given parameters.
     */
    public function resolve(
        int $stationId,
        ?int $operatorUserId,
        ?int $bandId,
        ?int $modeId,
        Carbon $startTime,
        ?string $externalSource = null,
    ): OperatingSession {
        return OperatingSession::firstOrCreate([
            'station_id' => $stationId,
            'operator_user_id' => $operatorUserId,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'is_transcription' => false,
            'end_time' => null,
        ], [
            'start_time' => $startTime,
            'power_watts' => 100,
            'qso_count' => 0,
            'external_source' => $externalSource,
        ]);
    }

    /**
     * Update last_activity_at on a session.
     */
    public function touchActivity(OperatingSession $session): void
    {
        $session->update(['last_activity_at' => now()]);
    }

    /**
     * Close a session by setting its end_time.
     */
    public function closeSession(OperatingSession $session): void
    {
        $session->update(['end_time' => now()]);
    }
}
