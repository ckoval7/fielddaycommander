<?php

namespace App\Services;

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;

class ClubSummaryReportService
{
    /**
     * Assemble all data needed to render the club summary PDF.
     *
     * @return array<string, mixed>
     */
    public function getData(EventConfiguration $config): array
    {
        $config->loadMissing(['section', 'operatingClass', 'event', 'bonuses.bonusType']);

        return [
            // Event identification
            'callsign' => $config->callsign,
            'club_name' => $config->club_name,
            'operating_class' => $config->operatingClass->code,
            'section' => $config->section->code,
            'section_name' => $config->section->name,
            'event_start' => $config->event->start_time,
            'event_end' => $config->event->end_time,

            // Score components
            'qso_base_points' => (int) $config->contacts()->notDuplicate()->sum('points'),
            'power_multiplier' => $config->calculatePowerMultiplier(),
            'qso_score' => $config->calculateQsoScore(),
            'bonus_score' => $config->calculateBonusScore(),
            'final_score' => $config->calculateFinalScore(),

            // Band/mode grid
            'bands' => $this->bands(),
            'band_mode_grid' => $this->bandModeGrid($config),

            // Bonuses (verified + claimed)
            'bonuses' => $config->bonuses->map(fn ($b) => [
                'name' => $b->bonusType?->name ?? 'Bonus',
                'points' => (int) $b->calculated_points,
                'is_verified' => $b->is_verified,
            ])->values()->all(),

            // Operator roster
            'operators' => $this->operatorRoster($config),

            // Metadata
            'generated_at' => now(),
        ];
    }

    /**
     * @return array<int, array{mode: string, cells: array<int, int>, total: int}>
     */
    private function bandModeGrid(EventConfiguration $config): array
    {
        $counts = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->selectRaw('band_id, mode_id, count(*) as contact_count')
            ->groupBy('band_id', 'mode_id')
            ->get()
            ->groupBy('mode_id');

        $grid = [];

        foreach (Mode::orderBy('name')->get() as $mode) {
            $modeCounts = $counts->get($mode->id, collect());
            $cells = [];
            $total = 0;

            foreach ($this->bands() as $band) {
                $entry = $modeCounts->firstWhere('band_id', $band['id']);
                $count = $entry ? (int) $entry->contact_count : 0;
                $cells[$band['id']] = $count;
                $total += $count;
            }

            if ($total > 0) {
                $grid[] = ['mode' => $mode->name, 'cells' => $cells, 'total' => $total];
            }
        }

        return $grid;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function bands(): array
    {
        return Band::allowedForFieldDay()
            ->ordered()
            ->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->all();
    }

    /**
     * @return array<int, array{call_sign: string, name: string, valid_qsos: int}>
     */
    private function operatorRoster(EventConfiguration $config): array
    {
        return Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->whereNotNull('logger_user_id')
            ->selectRaw('logger_user_id, count(*) as valid_qsos')
            ->groupBy('logger_user_id')
            ->with('logger')
            ->get()
            ->map(fn ($row) => [
                'call_sign' => $row->logger?->call_sign ?? '—',
                'name' => trim(($row->logger?->first_name ?? '').' '.($row->logger?->last_name ?? '')),
                'valid_qsos' => (int) $row->valid_qsos,
            ])
            ->sortByDesc('valid_qsos')
            ->values()
            ->all();
    }
}
