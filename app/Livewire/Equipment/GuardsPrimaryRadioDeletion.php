<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Keeps equipment list components from deleting a radio that a station in an
 * event that hasn't ended yet still uses as its primary radio.
 *
 * A station needs a primary radio, so deleting one would leave the station
 * showing "Not assigned". The person has to assign another radio first.
 * Stations from events that have ended don't block the delete: their
 * commitment records keep pointing at the soft-deleted radio.
 */
trait GuardsPrimaryRadioDeletion
{
    /**
     * Build the message explaining why this equipment can't be deleted, or
     * null when no station in an event that hasn't ended uses it as its
     * primary radio.
     */
    protected function primaryRadioDeletionBlocker(Equipment $equipment): ?string
    {
        return $this->partitionPrimaryRadios(new EloquentCollection([$equipment]))['blockedMessage'];
    }

    /**
     * Split equipment into items that can be deleted and radios that are the
     * primary radio of a station in an event that hasn't ended, with a message naming the blocked radios and
     * their stations.
     *
     * @param  EloquentCollection<int, Equipment>  $equipment
     * @return array{deletable: EloquentCollection<int, Equipment>, blockedMessage: string|null}
     */
    protected function partitionPrimaryRadios(EloquentCollection $equipment): array
    {
        $stationsByRadio = $this->stationsUsingAsPrimaryRadio($equipment->pluck('id'));

        [$blocked, $deletable] = $equipment->partition(
            fn (Equipment $item): bool => $stationsByRadio->has($item->id)
        );

        return [
            'deletable' => new EloquentCollection($deletable->values()->all()),
            'blockedMessage' => $blocked->isEmpty() ? null : $this->blockedMessage($blocked, $stationsByRadio),
        ];
    }

    /**
     * @param  Collection<int, Equipment>  $blocked
     * @param  Collection<int, EloquentCollection<int, Station>>  $stationsByRadio
     */
    private function blockedMessage(Collection $blocked, Collection $stationsByRadio): string
    {
        $stationCount = $blocked->sum(fn (Equipment $item): int => $stationsByRadio->get($item->id)->count());

        $assignHint = $stationCount === 1
            ? 'Assign another radio to that station first.'
            : 'Assign another radio to those stations first.';

        return $blocked
            ->map(fn (Equipment $item): string => $this->equipmentLabel($item).' is the primary radio for '
                .$this->stationList($stationsByRadio->get($item->id)).'.')
            ->push($assignHint)
            ->implode(' ');
    }

    /**
     * Load the stations in events that haven't ended (in progress or still to
     * come) that use any of the given equipment as their primary radio,
     * grouped by equipment ID.
     *
     * @param  Collection<int, int>  $equipmentIds
     * @return Collection<int, EloquentCollection<int, Station>>
     */
    private function stationsUsingAsPrimaryRadio(Collection $equipmentIds): Collection
    {
        if ($equipmentIds->isEmpty()) {
            return collect();
        }

        return Station::query()
            ->whereIn('radio_equipment_id', $equipmentIds)
            ->whereHas('eventConfiguration.event', fn (Builder $query): Builder => $query->notEnded())
            ->with('eventConfiguration.event:id,name')
            ->orderBy('name')
            ->get()
            ->groupBy('radio_equipment_id');
    }

    /**
     * Format station names with their event so same-named stations in
     * different events can be told apart.
     *
     * @param  EloquentCollection<int, Station>  $stations
     */
    private function stationList(EloquentCollection $stations): string
    {
        return $stations
            ->map(function (Station $station): string {
                $eventName = $station->eventConfiguration?->event?->name;

                return $eventName ? $station->name.' in '.$eventName : $station->name;
            })
            ->implode(', ');
    }

    private function equipmentLabel(Equipment $equipment): string
    {
        $label = trim($equipment->make.' '.$equipment->model);

        return $label !== '' ? $label : 'This radio';
    }
}
