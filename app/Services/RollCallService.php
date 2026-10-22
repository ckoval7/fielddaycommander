<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\NetPriority;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\RollCall;
use App\Models\RollCallStation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs roll calls (PAR) on a net.
 *
 * The expected stations are the net positions the net control station list
 * shows, less any marked out of service. A station is accounted for when an
 * entry from it is logged while the roll call is open, or when net control
 * marks it, which logs a "PAR" entry from it. Starting and ending the roll
 * call log entries too, so the whole roll call reads in order on the ICS-309.
 */
class RollCallService
{
    /**
     * Who net control addresses when it opens and closes a roll call.
     */
    public const ALL_STATIONS = 'ALL STATIONS';

    public function __construct(
        protected StationLastHeardService $stations,
        protected TacticalCallsignResolver $resolver,
    ) {}

    /**
     * Start a roll call on the net, or return the one already open.
     *
     * @throws RuntimeException when the net has no stations to call
     */
    public function start(Net $net, User $user): RollCall
    {
        return DB::transaction(function () use ($net, $user): RollCall {
            $open = $net->rollCalls()->active()->lockForUpdate()->first();

            if ($open !== null) {
                return $open;
            }

            $expected = $this->stations->forEvent($net->eventConfiguration, $net)
                ->reject(fn (array $row) => $row['unavailable']);

            if ($expected->isEmpty()) {
                throw new RuntimeException('No stations to call. Add a tactical callsign or location to stations first.');
            }

            $rollCall = $net->rollCalls()->create([
                'user_id' => $user->id,
                'started_at' => appNow(),
            ]);

            $rollCall->stations()->createMany($expected->map(fn (array $row) => [
                'station_id' => $row['station']->id,
                'name' => $row['name'],
                'callsign' => $row['operator'],
            ])->all());

            $this->log($net, $user, $this->netControl($net), self::ALL_STATIONS, sprintf('ROLL CALL (PAR): %d %s', $expected->count(), $expected->count() === 1 ? 'STATION' : 'STATIONS'));

            return $rollCall;
        });
    }

    /**
     * Net control heard the station: log its "PAR" answer and account for it.
     */
    public function markAccounted(RollCallStation $station, User $user): void
    {
        if ($station->isAccounted() || ! $station->rollCall->isActive()) {
            return;
        }

        $net = $station->rollCall->net;
        $entry = $this->log($net, $user, $station->name, $this->netControl($net), 'PAR');

        $station->update(['accounted_at' => $entry->logged_at, 'net_log_entry_id' => $entry->id]);
    }

    /**
     * Take back a mark made by mistake, removing the "PAR" entry it logged.
     */
    public function unmark(RollCallStation $station): ?NetLogEntry
    {
        if (! $station->isAccounted() || ! $station->rollCall->isActive()) {
            return null;
        }

        $entry = $station->netLogEntry;
        $entry?->delete();
        $station->update(['accounted_at' => null, 'net_log_entry_id' => null]);

        return $entry;
    }

    /**
     * Account for any expected station the entry came from, when its net has
     * a roll call open.
     */
    public function recordEntry(NetLogEntry $entry): void
    {
        $rollCall = RollCall::active()->where('net_id', $entry->net_id)->with('stations')->first();

        $rollCall?->stations
            ->reject(fn (RollCallStation $station) => $station->isAccounted())
            ->filter(fn (RollCallStation $station) => $station->answersTo($entry->from_station) || $station->answersTo($entry->from_callsign))
            ->each(fn (RollCallStation $station) => $station->update(['accounted_at' => $entry->logged_at]));
    }

    /**
     * Close the roll call and log its result, naming anyone not accounted for.
     */
    public function end(RollCall $rollCall, User $user): void
    {
        if (! $rollCall->isActive()) {
            return;
        }

        $stations = $rollCall->stations()->orderBy('id')->get();
        $missing = $stations->reject(fn (RollCallStation $station) => $station->isAccounted());

        $result = sprintf('ROLL CALL COMPLETE: %d OF %d ACCOUNTED FOR', $stations->count() - $missing->count(), $stations->count());

        if ($missing->isNotEmpty()) {
            $result .= '. NOT ACCOUNTED FOR: '.mb_strtoupper($missing->pluck('name')->implode(', '));
        }

        $rollCall->update(['ended_at' => appNow()]);
        $this->log($rollCall->net, $user, $this->netControl($rollCall->net), self::ALL_STATIONS, $result);
    }

    protected function netControl(Net $net): string
    {
        return $net->net_control_station ?: 'NCS';
    }

    protected function log(Net $net, User $user, string $from, ?string $to, string $message): NetLogEntry
    {
        $config = $net->eventConfiguration;

        return NetLogEntry::create([
            'event_configuration_id' => $net->event_configuration_id,
            'net_id' => $net->id,
            'user_id' => $user->id,
            'logged_at' => appNow(),
            'from_station' => $from,
            'to_station' => $to,
            'from_callsign' => $this->resolver->resolve($config, $from),
            'to_callsign' => $this->resolver->resolve($config, $to),
            'message' => $message,
            'priority' => NetPriority::Routine,
        ]);
    }
}
