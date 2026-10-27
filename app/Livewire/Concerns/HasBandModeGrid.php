<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Concerns;

use App\Models\Mode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

trait HasBandModeGrid
{
    /**
     * Return the base Contact query for the band/mode grid.
     *
     * Each consumer applies its own scopes (e.g. leaving out dupes). GOTA
     * contacts are split into their own row, as the ARRL breakdown lists them.
     */
    abstract protected function bandModeGridQuery(): Builder;

    /**
     * Aggregate contacts by band and mode into a grid structure: one row per
     * mode for the primary stations, then a GOTA row when the GOTA station
     * made contacts.
     *
     * @return array<int, array{label: string, mode: Mode|null, cells: array<int, int>, total_count: int, total_points: int}>
     */
    #[Computed]
    public function bandModeGrid(): array
    {
        if (! $this->config()) {
            return [];
        }

        $counts = $this->bandModeGridQuery()
            ->selectRaw('band_id, mode_id, is_gota_contact, count(*) as contact_count, sum(points) as total_points')
            ->groupBy('band_id', 'mode_id', 'is_gota_contact')
            ->get();

        $primary = $counts->filter(fn ($entry) => ! $entry->is_gota_contact)->groupBy('mode_id');
        $gota = $counts->filter(fn ($entry) => (bool) $entry->is_gota_contact);

        $data = [];

        foreach ($this->modes as $mode) {
            $data[] = ['label' => $mode->name, 'mode' => $mode, ...$this->bandModeGridRow($primary->get($mode->id, collect()))];
        }

        if ($gota->isNotEmpty()) {
            $data[] = ['label' => 'GOTA', 'mode' => null, ...$this->bandModeGridRow($gota)];
        }

        return $data;
    }

    /**
     * Per-band counts and row totals for one grid row's aggregate entries.
     *
     * @param  Collection<int, mixed>  $entries
     * @return array{cells: array<int, int>, total_count: int, total_points: int}
     */
    private function bandModeGridRow(Collection $entries): array
    {
        $cells = [];
        $totalCount = 0;
        $totalPoints = 0;

        foreach ($this->bands as $band) {
            $bandEntries = $entries->where('band_id', $band->id);
            $count = (int) $bandEntries->sum('contact_count');
            $cells[$band->id] = $count;
            $totalCount += $count;
            $totalPoints += (int) $bandEntries->sum('total_points');
        }

        return ['cells' => $cells, 'total_count' => $totalCount, 'total_points' => $totalPoints];
    }

    /**
     * Sum contact counts per band across all modes.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function bandColumnTotals(): array
    {
        $totals = [];

        foreach ($this->bandModeGrid as $row) {
            foreach ($row['cells'] as $bandId => $count) {
                $totals[$bandId] = ($totals[$bandId] ?? 0) + $count;
            }
        }

        return $totals;
    }
}
