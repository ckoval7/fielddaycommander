<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Maps tactical callsigns ("Aid 7", "Net Control") to the licensed operator
 * currently on duty there.
 *
 * A station's operator is whoever has an open, live operating session on it.
 * A shift role's operator is whoever most recently checked in to a shift for
 * that role and has not checked out. Station and role names stand in for a
 * tactical callsign when none is set.
 */
class TacticalCallsignResolver
{
    /**
     * Tactical names offered as suggestions in the net log.
     *
     * @return Collection<int, string>
     */
    public function tacticalNames(EventConfiguration $config): Collection
    {
        $stations = $config->stations()
            ->whereNotNull('tactical_callsign')
            ->pluck('tactical_callsign');

        $roles = $config->shiftRoles()
            ->whereNotNull('tactical_callsign')
            ->pluck('tactical_callsign');

        return $stations->concat($roles)
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Callsign of the operator on duty under a tactical name, or null when
     * nobody is on duty there or the name is not a known position.
     */
    public function resolve(EventConfiguration $config, ?string $tacticalName): ?string
    {
        $name = trim((string) $tacticalName);

        if ($name === '') {
            return null;
        }

        $station = $this->matchByName($config->stations(), $name);

        if ($station !== null) {
            return $this->stationOperatorCallsign($station);
        }

        $role = $this->matchByName($config->shiftRoles(), $name);

        return $role !== null ? $this->roleOperatorCallsign($config, $role) : null;
    }

    /**
     * The station or role whose tactical callsign is the name, or, when it
     * has none, whose own name is.
     *
     * @template TModel of Station|ShiftRole
     *
     * @param  HasMany<TModel, EventConfiguration>  $relation
     * @return TModel|null
     */
    protected function matchByName(HasMany $relation, string $name): Station|ShiftRole|null
    {
        $needle = mb_strtolower($name);

        return $relation
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(tactical_callsign) = ?', [$needle])
                ->orWhere(fn ($query) => $query
                    ->whereNull('tactical_callsign')
                    ->whereRaw('LOWER(name) = ?', [$needle])))
            ->first();
    }

    /**
     * Callsign of the operator on duty at each station, keyed by station id,
     * in one query. Stations nobody is operating are left out.
     *
     * @param  iterable<int, int>  $stationIds
     * @return array<int, string>
     */
    public function stationOperatorCallsigns(iterable $stationIds): array
    {
        return OperatingSession::query()
            ->active()
            ->whereIn('station_id', $stationIds)
            ->where('is_transcription', false)
            ->with('operator:id,call_sign')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (OperatingSession $session) => filled($session->operator?->call_sign))
            ->mapWithKeys(fn (OperatingSession $session) => [$session->station_id => $session->operator->call_sign])
            ->all();
    }

    protected function stationOperatorCallsign(Station $station): ?string
    {
        return $this->stationOperatorCallsigns([$station->id])[$station->id] ?? null;
    }

    protected function roleOperatorCallsign(EventConfiguration $config, ShiftRole $role): ?string
    {
        return ShiftAssignment::query()
            ->checkedIn()
            ->whereHas('shift', fn ($query) => $query->forEvent($config->id)->forRole($role->id))
            ->with('user')
            ->latest('checked_in_at')
            ->first()
            ?->user
            ?->call_sign;
    }
}
