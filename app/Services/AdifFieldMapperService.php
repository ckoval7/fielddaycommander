<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Section;
use App\Models\Station;
use App\Support\EventSections;
use Illuminate\Support\Collection;

class AdifFieldMapperService
{
    public function __construct(private readonly UserResolverService $userResolver) {}

    /**
     * Auto-map staged records against the database.
     *
     * Records with no operator, section or station value at all are counted
     * under missing_*, so the wizard can offer one default for each.
     *
     * @return array{unmapped_bands: array<string>, unmapped_modes: array<string>, unmapped_sections: array<string>, unmapped_stations: array<string>, missing_operators: int, missing_sections: int, missing_stations: int}
     */
    public function autoMap(AdifImport $import): array
    {
        $report = [
            'unmapped_bands' => [],
            'unmapped_modes' => [],
            'unmapped_sections' => [],
            'unmapped_stations' => [],
            'missing_operators' => 0,
            'missing_sections' => 0,
            'missing_stations' => 0,
        ];

        // YT and MX map to TER and DX unless the event recognises them.
        $sections = EventSections::foldIdsByCode(
            Section::query()->where('is_active', true)->pluck('id', 'code')->toArray(),
            $import->eventConfiguration?->event,
        );

        $stations = Station::query()
            ->where('event_configuration_id', $import->event_configuration_id)
            ->get(['id', 'name', 'hostname'])
            ->flatMap(fn ($s) => collect([
                strtoupper($s->name) => $s->id,
                strtoupper((string) $s->hostname) => $s->id,
            ])->filter(fn ($id, $key) => $key !== ''))
            ->toArray();

        $records = $import->records()
            ->where('status', AdifRecordStatus::Pending)
            ->get(['id', 'band_name', 'mode_name', 'section_code', 'station_identifier', 'operator_callsign']);

        // Resolve each distinct value once, then write each distinct mapping
        // with one UPDATE, instead of several queries per record.
        $bandIds = $this->resolveDistinct($records->pluck('band_name'), fn (string $name) => app(BandResolverService::class)->resolveByName($name));
        $modeIds = $this->resolveDistinct($records->pluck('mode_name'), fn (string $name) => app(ModeResolverService::class)->resolve($name));
        $operatorIds = $this->resolveDistinct($records->pluck('operator_callsign'), fn (string $callsign) => $this->userResolver->resolveOrCreate($callsign)->id);

        /** @var array<string, array{values: array<string, int|string|null>, ids: array<int, int>}> $updates */
        $updates = [];

        foreach ($records as $record) {
            $report['missing_operators'] += blank($record->operator_callsign) ? 1 : 0;
            $report['missing_sections'] += blank($record->section_code) ? 1 : 0;
            $report['missing_stations'] += blank($record->station_identifier) ? 1 : 0;

            $values = [
                'band_id' => $this->lookup($record->band_name, $bandIds, 'unmapped_bands', $report),
                'mode_id' => $this->lookup($record->mode_name, $modeIds, 'unmapped_modes', $report),
                'section_id' => $this->lookup($record->section_code, $sections, 'unmapped_sections', $report, upper: true),
                'station_id' => $this->lookup($record->station_identifier, $stations, 'unmapped_stations', $report, upper: true),
                'operator_user_id' => filled($record->operator_callsign) ? ($operatorIds[$record->operator_callsign] ?? null) : null,
                'status' => AdifRecordStatus::Mapped->value,
            ];

            $signature = implode('|', array_map(fn ($value) => (string) $value, $values));
            $updates[$signature]['values'] = $values;
            $updates[$signature]['ids'][] = $record->id;
        }

        foreach ($updates as $update) {
            foreach (array_chunk($update['ids'], 1000) as $ids) {
                AdifImportRecord::query()->whereKey($ids)->update($update['values']);
            }
        }

        $report['unmapped_bands'] = array_unique($report['unmapped_bands']);
        $report['unmapped_modes'] = array_unique($report['unmapped_modes']);
        $report['unmapped_sections'] = array_unique($report['unmapped_sections']);
        $report['unmapped_stations'] = array_unique($report['unmapped_stations']);

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
            $event = $import->eventConfiguration?->event;

            foreach ($mappings['sections'] as $sectionCode => $sectionId) {
                // A YT or MX picked by hand stores as TER or DX unless the
                // event recognises it, as auto-mapping does.
                $import->records()
                    ->where('section_code', $sectionCode)
                    ->whereNull('section_id')
                    ->update(['section_id' => EventSections::countedSectionId((int) $sectionId, $event)]);
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
     * Fill in the operator, section or station on records whose ADIF file
     * left that field out entirely, such as a WSJT-X log with no operator
     * callsign. Records with a value that just didn't match are untouched;
     * those go through applyFieldMapping().
     *
     * @param  array{operator_user_id?: int, section_id?: int, station_id?: int}  $defaults
     */
    public function applyMissingDefaults(AdifImport $import, array $defaults): void
    {
        $sourceColumns = [
            'operator_user_id' => 'operator_callsign',
            'section_id' => 'section_code',
            'station_id' => 'station_identifier',
        ];

        foreach ($sourceColumns as $idColumn => $sourceColumn) {
            if (! isset($defaults[$idColumn])) {
                continue;
            }

            $import->records()
                ->whereNull($idColumn)
                ->where(fn ($query) => $query->whereNull($sourceColumn)->orWhere($sourceColumn, ''))
                ->update([$idColumn => $defaults[$idColumn]]);
        }
    }

    /**
     * Resolve each distinct non-null value once.
     *
     * @param  Collection<int, string|null>  $values
     * @param  callable(string): ?int  $resolver
     * @return array<string, int|null>
     */
    private function resolveDistinct(Collection $values, callable $resolver): array
    {
        $resolved = [];

        foreach ($values->filter(fn ($value) => filled($value))->unique() as $value) {
            $resolved[$value] = $resolver($value);
        }

        return $resolved;
    }

    /**
     * Look up a record's value in a resolved map, noting it as unmapped when
     * it has no match.
     *
     * @param  array<string, int|null>  $map
     * @param  array<string, array<string>>  $report
     */
    private function lookup(?string $value, array $map, string $reportKey, array &$report, bool $upper = false): ?int
    {
        if (blank($value)) {
            return null;
        }

        $id = $map[$upper ? strtoupper($value) : $value] ?? null;

        if ($id === null) {
            $report[$reportKey][] = $value;
        }

        return $id;
    }
}
