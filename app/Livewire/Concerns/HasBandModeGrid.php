<?php

namespace App\Livewire\Concerns;

use App\Models\Mode;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

trait HasBandModeGrid
{
    /**
     * Return the base Contact query for the band/mode grid.
     *
     * Each consumer applies its own scopes (e.g. excluding GOTA contacts).
     */
    abstract protected function bandModeGridQuery(): Builder;

    /**
     * Aggregate contacts by band and mode into a grid structure.
     *
     * @return array<int, array{mode: Mode, cells: array<int, int>, total_count: int, total_points: int}>
     */
    #[Computed]
    public function bandModeGrid(): array
    {
        if (! $this->config()) {
            return [];
        }

        $counts = $this->bandModeGridQuery()
            ->selectRaw('band_id, mode_id, count(*) as contact_count, sum(points) as total_points')
            ->groupBy('band_id', 'mode_id')
            ->get()
            ->groupBy('mode_id');

        $data = [];

        foreach ($this->modes as $mode) {
            $modeCounts = $counts->get($mode->id, collect());
            $cells = [];
            $totalCount = 0;
            $totalPoints = 0;

            foreach ($this->bands as $band) {
                $entry = $modeCounts->firstWhere('band_id', $band->id);
                $count = $entry ? (int) $entry->contact_count : 0;
                $cells[$band->id] = $count;
                $totalCount += $count;
                $totalPoints += $entry ? (int) $entry->total_points : 0;
            }

            $data[] = [
                'mode' => $mode,
                'cells' => $cells,
                'total_count' => $totalCount,
                'total_points' => $totalPoints,
            ];
        }

        return $data;
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
