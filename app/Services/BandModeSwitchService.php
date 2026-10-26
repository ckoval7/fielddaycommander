<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Exceptions\BandModeSwitchException;
use App\Models\Band;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use Illuminate\Support\Facades\DB;

/**
 * Moves an operator to another band or mode without leaving the logging screen.
 *
 * A session operates on one band and mode, so a switch ends the current
 * session and starts a new one on the same station with the same operator,
 * power and supervision, the way End Session and a new session would.
 */
class BandModeSwitchService
{
    /**
     * End the session and start one on the new band and mode, or return the
     * session unchanged when nothing changes.
     *
     * @throws BandModeSwitchException when the band or mode can't be used or
     *                                 another station is already on it
     */
    public function switchTo(OperatingSession $session, int $bandId, int $modeId): OperatingSession
    {
        if ($session->end_time !== null) {
            throw new BandModeSwitchException('This session has ended. Start a new one from Station Select.');
        }

        if ($session->band_id === $bandId && $session->mode_id === $modeId) {
            return $session;
        }

        $band = Band::query()->allowedForFieldDay()->find($bandId);
        $mode = Mode::query()->find($modeId);

        if ($band === null || $mode === null) {
            throw new BandModeSwitchException('That band or mode is not available for this event.');
        }

        $station = $session->station;

        if ($station->isUnavailable()) {
            throw new BandModeSwitchException("{$station->name} has been marked unavailable by a station captain.");
        }

        return DB::transaction(function () use ($session, $station, $band, $mode): OperatingSession {
            // Serializes switches and new sessions in this event so two
            // stations can't claim the same band-mode at once.
            EventConfiguration::query()->whereKey($station->event_configuration_id)->lockForUpdate()->first();

            // Per FD Rule 6.9: no two transmitters on the same band-mode at
            // the same time. GOTA stations may use any band-mode (Rule 4.1).
            if (! $station->is_gota) {
                $conflict = OperatingSession::query()
                    ->active()
                    ->whereKeyNot($session->id)
                    ->where('band_id', $band->id)
                    ->where('mode_id', $mode->id)
                    ->whereHas('station', fn ($query) => $query
                        ->where('event_configuration_id', $station->event_configuration_id)
                        ->where('is_gota', false))
                    ->with('station')
                    ->first();

                if ($conflict !== null) {
                    $stationName = $conflict->station->name ?? 'another station';

                    throw new BandModeSwitchException("{$band->name} {$mode->name} is already in use at {$stationName}.");
                }
            }

            $now = appNow();

            $session->update(['end_time' => $now]);

            return OperatingSession::create([
                'station_id' => $session->station_id,
                'operator_user_id' => $session->operator_user_id,
                'previous_session_id' => $session->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'power_watts' => $session->power_watts,
                'start_time' => $now,
                'qso_count' => 0,
                'is_supervised' => (bool) $session->is_supervised,
            ]);
        });
    }
}
