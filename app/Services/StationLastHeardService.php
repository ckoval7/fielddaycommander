<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\EventConfiguration;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\Station;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When net control last heard each station, from the net log.
 *
 * A station is heard when a net log entry at the event is to or from its
 * tactical callsign (its name when it has none) or the callsign of the
 * operator on duty there. Nothing else is tracked.
 *
 * The stations listed are the event's net positions: those with a tactical
 * callsign or a location. On a net with an overdue threshold, a station
 * quiet for longer than that is overdue. A station never heard is measured
 * from the net's first entry, so a net set up days ahead doesn't flag
 * everyone the moment it goes on the air.
 */
class StationLastHeardService
{
    public function __construct(protected TacticalCallsignResolver $resolver) {}

    /**
     * @return Collection<int, array{station: Station, name: string, location: ?string, operator: ?string, last_heard_at: ?CarbonInterface, overdue_at: ?CarbonInterface, overdue: bool, unavailable: bool}>
     */
    public function forEvent(EventConfiguration $config, ?Net $net = null): Collection
    {
        $stations = $config->stations()
            ->where(fn ($query) => $query->whereNotNull('tactical_callsign')->orWhereNotNull('location'))
            ->get();

        if ($stations->isEmpty()) {
            return collect();
        }

        $operators = $this->resolver->stationOperatorCallsigns($stations->modelKeys());

        /** @var array<int, list<string>> $tokens station id => lowercased names it answers to */
        $tokens = $stations->mapWithKeys(fn (Station $station) => [$station->id => collect([
            $station->tactical_callsign ?: $station->name,
            $operators[$station->id] ?? null,
        ])->filter()->map(fn (string $name) => mb_strtolower(trim($name)))->unique()->values()->all()])->all();

        $heard = $this->lastHeardByName($config, collect($tokens)->flatten()->unique()->values()->all());
        $threshold = $net?->overdue_after_minutes;
        $netStartedAt = $threshold ? $this->firstEntryAt($net) : null;
        $now = appNow();

        return $stations
            ->map(function (Station $station) use ($tokens, $heard, $operators, $threshold, $netStartedAt, $now): array {
                $lastHeardAt = collect($tokens[$station->id])
                    ->map(fn (string $token) => $heard[$token] ?? null)
                    ->filter()
                    ->max();

                $unavailable = $station->isUnavailable();
                $since = $lastHeardAt ?? $netStartedAt;
                $overdueAt = ($threshold && $since && ! $unavailable) ? $since->copy()->addMinutes($threshold) : null;

                return [
                    'station' => $station,
                    'name' => $station->tactical_callsign ?: $station->name,
                    'location' => $station->location,
                    'operator' => $operators[$station->id] ?? null,
                    'last_heard_at' => $lastHeardAt,
                    'overdue_at' => $overdueAt,
                    'overdue' => $overdueAt !== null && $now->gte($overdueAt),
                    'unavailable' => $unavailable,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * The latest entry to or from each of the given names, keyed by the
     * lowercased name.
     *
     * @param  list<string>  $names  lowercased
     * @return array<string, Carbon>
     */
    protected function lastHeardByName(EventConfiguration $config, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $heard = [];

        foreach (['from_station', 'to_station'] as $column) {
            $rows = NetLogEntry::forEvent($config->id)
                ->whereIn(DB::raw("LOWER({$column})"), $names)
                ->groupBy(DB::raw("LOWER({$column})"))
                ->selectRaw("LOWER({$column}) as name, MAX(logged_at) as heard_at")
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $at = Carbon::parse($row->heard_at);

                if (! isset($heard[$row->name]) || $at->gt($heard[$row->name])) {
                    $heard[$row->name] = $at;
                }
            }
        }

        return $heard;
    }

    protected function firstEntryAt(Net $net): ?Carbon
    {
        $first = $net->entries()->min('logged_at');

        return $first === null ? null : Carbon::parse($first);
    }
}
