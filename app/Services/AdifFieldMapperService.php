<?php

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;

class AdifFieldMapperService
{
    /**
     * Auto-map staged records against the database.
     *
     * @return array{unmapped_bands: array<string>, unmapped_modes: array<string>, unmapped_sections: array<string>, unmapped_stations: array<string>, unmapped_operators: array<string>}
     */
    public function autoMap(AdifImport $import): array
    {
        $report = [
            'unmapped_bands' => [],
            'unmapped_modes' => [],
            'unmapped_sections' => [],
            'unmapped_stations' => [],
            'unmapped_operators' => [],
        ];

        $modes = Mode::query()->pluck('id', 'name')->toArray();
        $sections = Section::query()->where('is_active', true)->pluck('id', 'code')->toArray();

        $stations = Station::query()
            ->where('event_configuration_id', $import->event_configuration_id)
            ->pluck('id', 'name')
            ->toArray();

        $users = User::query()->whereNotNull('call_sign')
            ->pluck('id', 'call_sign')
            ->mapWithKeys(fn ($id, $callSign) => [strtoupper($callSign) => $id])
            ->toArray();

        $records = $import->records()->where('status', AdifRecordStatus::Pending)->get();

        foreach ($records as $record) {
            $this->mapBand($record, $report);
            $this->mapMode($record, $modes, $report);
            $this->mapSection($record, $sections, $report);
            $this->mapStation($record, $stations, $report);
            $this->mapOperator($record, $users, $report);

            $record->status = AdifRecordStatus::Mapped;
            $record->save();
        }

        $report['unmapped_bands'] = array_unique($report['unmapped_bands']);
        $report['unmapped_modes'] = array_unique($report['unmapped_modes']);
        $report['unmapped_sections'] = array_unique($report['unmapped_sections']);
        $report['unmapped_stations'] = array_unique($report['unmapped_stations']);
        $report['unmapped_operators'] = array_unique($report['unmapped_operators']);

        return $report;
    }

    /**
     * Detect class/section inconsistencies across QSOs for the same callsign.
     *
     * @return array<string, array{exchange_class?: array<string>, section_code?: array<string>}>
     */
    public function detectInconsistencies(AdifImport $import): array
    {
        $inconsistencies = [];

        $grouped = $import->records()
            ->select('callsign', 'exchange_class', 'section_code')
            ->get()
            ->groupBy('callsign');

        foreach ($grouped as $callsign => $records) {
            $classes = $records->pluck('exchange_class')->unique()->filter()->values()->toArray();
            $sections = $records->pluck('section_code')->unique()->filter()->values()->toArray();

            $issues = [];
            if (count($classes) > 1) {
                $issues['exchange_class'] = $classes;
            }
            if (count($sections) > 1) {
                $issues['section_code'] = $sections;
            }

            if (! empty($issues)) {
                $inconsistencies[$callsign] = $issues;
            }
        }

        return $inconsistencies;
    }

    /**
     * Apply user-chosen resolutions for callsign inconsistencies.
     *
     * @param  array<string, array{exchange_class?: string, section_code?: string}>  $resolutions
     */
    public function applyResolutions(AdifImport $import, array $resolutions): void
    {
        foreach ($resolutions as $callsign => $fields) {
            $query = $import->records()->where('callsign', $callsign);

            $updates = [];
            if (isset($fields['exchange_class'])) {
                $updates['exchange_class'] = $fields['exchange_class'];
            }
            if (isset($fields['section_code'])) {
                $updates['section_code'] = $fields['section_code'];
            }

            if (! empty($updates)) {
                $query->update($updates);
            }
        }
    }

    /**
     * Apply user-provided field mappings for unresolved bands, modes, stations, operators.
     *
     * @param  array{bands?: array<string, int>, modes?: array<string, int>, sections?: array<string, int>, stations?: array<string, int>, operators?: array<string, int>}  $mappings
     */
    public function applyFieldMapping(AdifImport $import, array $mappings): void
    {
        if (isset($mappings['bands'])) {
            foreach ($mappings['bands'] as $bandName => $bandId) {
                $import->records()
                    ->where('band_name', $bandName)
                    ->whereNull('band_id')
                    ->update(['band_id' => $bandId]);
            }
        }

        if (isset($mappings['modes'])) {
            foreach ($mappings['modes'] as $modeName => $modeId) {
                $import->records()
                    ->where('mode_name', $modeName)
                    ->whereNull('mode_id')
                    ->update(['mode_id' => $modeId]);
            }
        }

        if (isset($mappings['sections'])) {
            foreach ($mappings['sections'] as $sectionCode => $sectionId) {
                $import->records()
                    ->where('section_code', $sectionCode)
                    ->whereNull('section_id')
                    ->update(['section_id' => $sectionId]);
            }
        }

        if (isset($mappings['stations'])) {
            foreach ($mappings['stations'] as $stationIdentifier => $stationId) {
                $import->records()
                    ->where('station_identifier', $stationIdentifier)
                    ->whereNull('station_id')
                    ->update(['station_id' => $stationId]);
            }
        }

        if (isset($mappings['operators'])) {
            foreach ($mappings['operators'] as $operatorCallsign => $userId) {
                $import->records()
                    ->where('operator_callsign', $operatorCallsign)
                    ->whereNull('operator_user_id')
                    ->update(['operator_user_id' => $userId]);
            }
        }
    }

    /**
     * @param  array{unmapped_bands: array<string>}  $report
     */
    private function mapBand(AdifImportRecord $record, array &$report): void
    {
        if ($record->band_name === null) {
            return;
        }

        $bandId = app(BandResolverService::class)->resolveByName($record->band_name);
        if ($bandId !== null) {
            $record->band_id = $bandId;
        } else {
            $report['unmapped_bands'][] = $record->band_name;
        }
    }

    /**
     * @param  array<string, int>  $modes
     * @param  array{unmapped_modes: array<string>}  $report
     */
    private function mapMode(AdifImportRecord $record, array $modes, array &$report): void
    {
        if ($record->mode_name === null) {
            return;
        }

        $modeId = app(ModeResolverService::class)->resolve($record->mode_name);
        if ($modeId !== null) {
            $record->mode_id = $modeId;
        } else {
            $report['unmapped_modes'][] = $record->mode_name;
        }
    }

    /**
     * @param  array<string, int>  $sections
     * @param  array{unmapped_sections: array<string>}  $report
     */
    private function mapSection(AdifImportRecord $record, array $sections, array &$report): void
    {
        if ($record->section_code === null) {
            return;
        }

        $sectionId = $sections[strtoupper($record->section_code)] ?? null;
        if ($sectionId !== null) {
            $record->section_id = $sectionId;
        } else {
            $report['unmapped_sections'][] = $record->section_code;
        }
    }

    /**
     * @param  array<string, int>  $stations
     * @param  array{unmapped_stations: array<string>}  $report
     */
    private function mapStation(AdifImportRecord $record, array $stations, array &$report): void
    {
        if ($record->station_identifier === null) {
            return;
        }

        $stationId = $stations[$record->station_identifier] ?? null;
        if ($stationId !== null) {
            $record->station_id = $stationId;
        } else {
            $report['unmapped_stations'][] = $record->station_identifier;
        }
    }

    /**
     * @param  array<string, int>  $users
     * @param  array{unmapped_operators: array<string>}  $report
     */
    private function mapOperator(AdifImportRecord $record, array $users, array &$report): void
    {
        if ($record->operator_callsign === null) {
            return;
        }

        $userId = $users[strtoupper($record->operator_callsign)] ?? null;
        if ($userId !== null) {
            $record->operator_user_id = $userId;
        } else {
            $report['unmapped_operators'][] = $record->operator_callsign;
        }
    }
}
