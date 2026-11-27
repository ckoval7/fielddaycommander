<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\OperatingSession;
use Illuminate\Support\Carbon;

class SessionResolverService
{
    /**
     * Find or create an OperatingSession for the given parameters.
     *
     * A station runs one logger session at a time, so a contact under a new
     * operator, band or mode ends the station's other open logger sessions
     * instead of leaving them open until the idle sweep.
     */
    public function resolve(
        int $stationId,
        ?int $operatorUserId,
        ?int $bandId,
        ?int $modeId,
        Carbon $startTime,
        ?string $externalSource = null,
    ): OperatingSession {
        $this->closeOtherLoggerSessions($stationId, $operatorUserId, $bandId, $modeId, $startTime);

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
     * End the station's open logger sessions that don't match this operator,
     * band and mode, as of the contact that replaces them. Sessions started
     * in the browser are left alone.
     */
    protected function closeOtherLoggerSessions(
        int $stationId,
        ?int $operatorUserId,
        ?int $bandId,
        ?int $modeId,
        Carbon $contactTime,
    ): void {
        OperatingSession::query()
            ->where('station_id', $stationId)
            ->whereNotNull('external_source')
            ->whereNull('end_time')
            ->where('is_transcription', false)
            ->get()
            ->reject(fn (OperatingSession $session): bool => self::sameId($session->operator_user_id, $operatorUserId)
                && self::sameId($session->band_id, $bandId)
                && self::sameId($session->mode_id, $modeId))
            ->each(function (OperatingSession $session) use ($contactTime): void {
                $session->update(['end_time' => $contactTime->max($session->start_time)]);
            });
    }

    /**
     * Whether two nullable ids match, whether the driver returned one as a
     * string or an int.
     */
    private static function sameId(int|string|null $a, int|string|null $b): bool
    {
        return $a === null || $b === null ? $a === $b : (int) $a === (int) $b;
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
