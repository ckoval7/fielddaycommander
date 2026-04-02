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
            'qso_base_points' => (int) $config->contacts()->notDuplicate()->where('is_gota_contact', false)->sum('points'),
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

            // GOTA
            'has_gota_station' => $config->has_gota_station,
            'gota_callsign' => $config->gota_callsign,
            'gota_contact_count' => $config->contacts()->where('is_gota_contact', true)->notDuplicate()->count(),
            'gota_bonus' => $config->calculateGotaBonus(),
            'gota_coach_bonus' => $config->calculateGotaCoachBonus(),
            'gota_operators' => $this->gotaOperatorRoster($config),

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
            ->where('is_gota_contact', false)
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
            ->whereHas('logger', fn ($q) => $q->excludeSystem())
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

    /**
     * @return array<int, array{name: string, callsign: ?string, contacts: int}>
     */
    private function gotaOperatorRoster(EventConfiguration $config): array
    {
        // Registered GOTA operators
        $registered = Contact::where('event_configuration_id', $config->id)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNotNull('gota_operator_user_id')
            ->selectRaw('gota_operator_user_id, count(*) as contact_count')
            ->groupBy('gota_operator_user_id')
            ->with('gotaOperator')
            ->get()
            ->map(fn ($row) => [
                'name' => trim(($row->gotaOperator?->first_name ?? '').' '.($row->gotaOperator?->last_name ?? '')),
                'callsign' => $row->gotaOperator?->call_sign,
                'contacts' => (int) $row->contact_count,
            ]);

        // Non-registered GOTA operators (free-text fields)
        $unregistered = Contact::where('event_configuration_id', $config->id)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNull('gota_operator_user_id')
            ->whereNotNull('gota_operator_first_name')
            ->selectRaw('gota_operator_first_name, gota_operator_last_name, gota_operator_callsign, count(*) as contact_count')
            ->groupBy('gota_operator_first_name', 'gota_operator_last_name', 'gota_operator_callsign')
            ->get()
            ->map(fn ($row) => [
                'name' => trim($row->gota_operator_first_name.' '.$row->gota_operator_last_name),
                'callsign' => $row->gota_operator_callsign,
                'contacts' => (int) $row->contact_count,
            ]);

        return $registered->toBase()->merge($unregistered->toBase())
            ->sortByDesc('contacts')
            ->values()
            ->all();
    }
}
