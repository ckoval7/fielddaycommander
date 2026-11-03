<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

use App\Models\EventConfiguration;
use App\Models\OperatingSession;

/**
 * The band status display N3FJP PCs share: which station is on which band
 * and mode, from the PCs themselves and from the stations operating in
 * Field Day Commander.
 */
class N3fjpBandStatus
{
    /**
     * Seconds a station's band and mode is kept without an update. N3FJP
     * clients send theirs every 30 seconds and expire others' after 31.
     */
    private const TTL_SECONDS = 31;

    /**
     * Seconds between updates for Field Day Commander's own stations: twice
     * per expiry window, so a missed one doesn't drop them.
     */
    private const FDC_INTERVAL_SECONDS = 15;

    private int $lastFdcUpdateAt = 0;

    /** @var array<string, array{band: string, mode: string, at: int}> Band status by station name */
    private array $clients = [];

    public function __construct(
        private EventConfiguration $config,
        private N3fjpContactMapper $mapper,
    ) {}

    /**
     * Record an N3FJP PC's band and mode, returning the message to relay,
     * or null when it names no station.
     */
    public function record(N3fjpMessage $message): ?string
    {
        $station = N3fjpMessage::clean($message->field('STATION'));

        if ($station === '') {
            return null;
        }

        $band = N3fjpMessage::clean($message->field('BAND'));
        $mode = N3fjpMessage::clean($message->field('MODE'));
        $this->clients[$station] = ['band' => $band, 'mode' => $mode, 'at' => time()];

        return N3fjpMessage::bandAndMode($station, $band, $mode);
    }

    /**
     * Every current station's band and mode, for a PC that asked (WHO).
     *
     * @return list<string>
     */
    public function all(): array
    {
        $cutoff = time() - self::TTL_SECONDS;
        $messages = [];

        foreach ($this->clients as $station => $status) {
            if ($status['at'] < $cutoff) {
                unset($this->clients[$station]);

                continue;
            }

            $messages[] = N3fjpMessage::bandAndMode($station, $status['band'], $status['mode']);
        }

        return [...$messages, ...$this->fdcStations()];
    }

    /**
     * Field Day Commander's stations, when it's time to refresh them.
     *
     * @return list<string>
     */
    public function fdcStationsDue(): array
    {
        if (time() - $this->lastFdcUpdateAt < self::FDC_INTERVAL_SECONDS) {
            return [];
        }

        $this->lastFdcUpdateAt = time();

        return $this->fdcStations();
    }

    /**
     * Band and mode of every station operating in Field Day Commander: open
     * sessions, other than transcription and those started by N3FJP PCs
     * (which report themselves). A station an N3FJP PC is reporting under
     * the same name is left to the PC.
     *
     * @return list<string>
     */
    private function fdcStations(): array
    {
        $sessions = OperatingSession::query()
            ->active()
            ->where('is_transcription', false)
            ->where(fn ($query) => $query->whereNull('external_source')->orWhere('external_source', '!=', N3fjpContactMapper::SOURCE))
            ->whereHas('station', fn ($query) => $query->where('event_configuration_id', $this->config->id))
            ->with(['station', 'band', 'mode'])
            ->latest('start_time')
            ->get();

        $messages = [];

        foreach ($sessions as $session) {
            $station = N3fjpMessage::clean($session->station?->name);
            $band = $this->mapper->n3fjpBandName($session->band?->name);

            if ($station === '' || $band === null || isset($messages[$station]) || isset($this->clients[$station])) {
                continue;
            }

            $messages[$station] = N3fjpMessage::bandAndMode($station, $band, $this->mapper->modeGroup($session->mode?->name));
        }

        return array_values($messages);
    }
}
