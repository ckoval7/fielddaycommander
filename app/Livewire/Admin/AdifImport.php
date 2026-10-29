<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Admin;

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Jobs\ImportAdifContacts;
use App\Jobs\ValidateAdifImport;
use App\Models\AdifImport as AdifImportModel;
use App\Models\AdifImportRecord;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifFieldMapperService;
use App\Services\AdifParserService;
use App\Services\EventContextService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class AdifImport extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * Staged records written per INSERT on upload.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Rows shown per page of the review table.
     */
    public const RECORDS_PER_PAGE = 50;

    /**
     * Review table filters and the record statuses each one shows.
     *
     * @var array<string, array<int, AdifRecordStatus>>
     */
    private const RECORD_FILTERS = [
        'invalid' => [AdifRecordStatus::Invalid],
        'new' => [AdifRecordStatus::Ready],
        'duplicate' => [AdifRecordStatus::DuplicateMatch],
        'skipped' => [AdifRecordStatus::Skipped],
    ];

    public int $step = 1;

    #[Locked]
    public ?int $importId = null;

    /** @var TemporaryUploadedFile|null */
    public $adifFile = null;

    /** @var array{total: int, date_range: string, bands: array<string>, modes: array<string>, stations: array<string>} */
    public array $uploadSummary = [];

    /** @var array{unmapped_bands: array<string>, unmapped_modes: array<string>, unmapped_sections: array<string>, unmapped_stations: array<string>} */
    public array $mappingReport = [];

    /** @var array<string, array{exchange_class?: array<string>, section_code?: array<string>}> */
    public array $inconsistencies = [];

    /** @var array{new: int, merge: int, skip: int, invalid: int} */
    public array $matchSummary = [];

    /** @var array{invalid_count: int, valid_count: int} */
    public array $validationResult = [];

    /** @var array<string, int> User-selected band mappings */
    public array $bandMappings = [];

    /** @var array<string, int> User-selected mode mappings */
    public array $modeMappings = [];

    /** @var array<string, int> User-selected section mappings */
    public array $sectionMappings = [];

    /** @var array<string, int> User-selected station mappings */
    public array $stationMappings = [];

    /** @var array<string, int> User-selected operator mappings */
    public array $operatorMappings = [];

    /** @var array<string, array{exchange_class?: string, section_code?: string}> User-chosen resolutions */
    public array $resolutions = [];

    public string $importStatus = '';

    /** Which records the review table shows: all, invalid, new, duplicate or skipped. */
    public string $recordFilter = 'all';

    public function mount(): void
    {
        $event = app(EventContextService::class)->getContextEvent();
        if (! $event?->eventConfiguration) {
            abort(404, 'No active event configuration found.');
        }
    }

    public function uploadFile(): void
    {
        $this->validate([
            'adifFile' => ['required', 'file', 'max:10240'],
        ]);

        $event = app(EventContextService::class)->getContextEvent();
        $content = $this->adifFile->get();
        $parser = new AdifParserService;
        $result = $parser->parse($content);

        if (! empty($result['errors'])) {
            $this->addError('adifFile', implode(', ', $result['errors']));

            return;
        }

        if (empty($result['records'])) {
            $this->addError('adifFile', 'No contacts found. Select an ADIF file containing contact records.');

            return;
        }

        $import = AdifImportModel::create([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $event->eventConfiguration->id,
            'user_id' => auth()->id(),
            'filename' => $this->adifFile->getClientOriginalName(),
            'status' => AdifImportStatus::PendingMapping,
            'total_records' => count($result['records']),
        ]);

        $this->importId = $import->id;

        $now = now();
        $rows = [];

        foreach ($result['records'] as $record) {
            $qsoTime = $this->parseQsoTime($record['QSO_DATE'] ?? null, $record['TIME_ON'] ?? null);

            $rows[] = [
                'adif_import_id' => $import->id,
                'raw_data' => json_encode($record),
                'callsign' => isset($record['CALL']) ? strtoupper(trim($record['CALL'])) : null,
                'qso_time' => $qsoTime?->format('Y-m-d H:i:s'),
                'band_name' => $record['BAND'] ?? null,
                'mode_name' => $record['MODE'] ?? null,
                'section_code' => $record['ARRL_SECT'] ?? null,
                'exchange_class' => $record['APP_N1MM_EXCHANGE1'] ?? $record['CLASS'] ?? null,
                'station_identifier' => $record['APP_N1MM_NETBIOSNAME'] ?? $record['STATION_CALLSIGN'] ?? null,
                'operator_callsign' => $record['OPERATOR'] ?? null,
                'status' => AdifRecordStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::INSERT_CHUNK_SIZE) {
                AdifImportRecord::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            AdifImportRecord::query()->insert($rows);
        }

        $mapper = app(AdifFieldMapperService::class);
        $this->mappingReport = $mapper->autoMap($import);
        $this->inconsistencies = $mapper->detectInconsistencies($import);

        $this->uploadSummary = $this->buildUploadSummary($import, $result);

        AuditLog::log('adif.import.uploaded', auditable: $import, newValues: [
            'filename' => $this->adifFile->getClientOriginalName(),
            'total_records' => count($result['records']),
        ]);

        $this->step = 2;
    }

    public function applyMappingsAndContinue(): void
    {
        $import = $this->findOwnedImport();
        $mapper = app(AdifFieldMapperService::class);

        $this->bandMappings = $this->withoutBlankSelections($this->bandMappings);
        $this->modeMappings = $this->withoutBlankSelections($this->modeMappings);
        $this->sectionMappings = $this->withoutBlankSelections($this->sectionMappings);
        $this->stationMappings = $this->withoutBlankSelections($this->stationMappings);
        $this->operatorMappings = $this->withoutBlankSelections($this->operatorMappings);

        if (! $this->mappingsReferenceValidRecords($import)) {
            $this->addError('mapping', 'One or more field mappings are invalid for this event. Please review your selections.');

            return;
        }

        $fieldMappings = [];
        if (! empty($this->bandMappings)) {
            $fieldMappings['bands'] = $this->bandMappings;
        }
        if (! empty($this->modeMappings)) {
            $fieldMappings['modes'] = $this->modeMappings;
        }
        if (! empty($this->sectionMappings)) {
            $fieldMappings['sections'] = $this->sectionMappings;
        }
        if (! empty($this->stationMappings)) {
            $fieldMappings['stations'] = $this->stationMappings;
        }
        if (! empty($this->operatorMappings)) {
            $fieldMappings['operators'] = $this->operatorMappings;
        }

        if (! empty($fieldMappings)) {
            $mapper->applyFieldMapping($import, $fieldMappings);
        }

        if (! empty($this->resolutions)) {
            $mapper->applyResolutions($import, $this->resolutions);
        }

        $import->update([
            'status' => AdifImportStatus::PendingReview,
            'field_mapping' => $fieldMappings,
            'station_mapping' => $this->stationMappings,
            'operator_mapping' => $this->operatorMappings,
            'inconsistencies_resolved' => $this->resolutions,
            'mapped_records' => $import->records()->where('status', '!=', AdifRecordStatus::Pending->value)->count(),
        ]);

        $unmappedCount = $import->records()
            ->where(function ($query) {
                $query->whereNull('band_id')
                    ->orWhereNull('mode_id')
                    ->orWhereNull('section_id')
                    ->orWhereNull('station_id')
                    ->orWhereNull('operator_user_id');
            })
            ->count();

        if ($unmappedCount > 0) {
            $this->addError('mapping', "{$unmappedCount} ".str('record')->plural($unmappedCount).' with unmapped fields. Please map all fields before continuing.');

            return;
        }

        // Matching and validation run on the queue; the wizard polls
        // refreshProgress() until the summary is ready.
        $import->update(['status' => AdifImportStatus::Validating, 'progress_processed' => 0, 'progress_total' => 0]);
        ValidateAdifImport::dispatch($import->id);

        $this->step = 3;
        $this->syncProgress($import->refresh());
    }

    public function executeImport(): void
    {
        $import = $this->findOwnedImport();

        if ($import->status !== AdifImportStatus::PendingReview || $this->hasInvalidRecords) {
            return;
        }

        $import->update([
            'status' => AdifImportStatus::Importing,
            'progress_processed' => 0,
            'progress_total' => $import->records()
                ->whereIn('status', [AdifRecordStatus::Ready, AdifRecordStatus::DuplicateMatch])
                ->count(),
        ]);

        $this->recordFilter = 'all';
        $this->resetPage();

        // The import runs on the queue in time-boxed runs; the wizard polls
        // refreshProgress() for progress and the final result.
        ImportAdifContacts::dispatch($import->id);

        $this->syncProgress($import->refresh());
    }

    /**
     * Polled while validation or the import runs on the queue.
     */
    public function refreshProgress(): void
    {
        if (! $this->importId) {
            return;
        }

        $this->syncProgress($this->findOwnedImport());
    }

    /**
     * Reflect the import's stored status in the wizard.
     */
    private function syncProgress(AdifImportModel $import): void
    {
        unset($this->currentImport, $this->importRecords, $this->hasInvalidRecords);

        $this->importStatus = match ($import->status) {
            AdifImportStatus::Validating => 'validating',
            AdifImportStatus::Importing => 'importing',
            AdifImportStatus::Completed => 'completed',
            AdifImportStatus::Failed => 'failed',
            default => '',
        };

        if ($import->status === AdifImportStatus::PendingReview) {
            $this->matchSummary = $this->summarizeRecords($import);
        }
    }

    /**
     * @return array{new: int, merge: int, skip: int, invalid: int}
     */
    private function summarizeRecords(AdifImportModel $import): array
    {
        $counts = $import->records()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'new' => (int) ($counts[AdifRecordStatus::Ready->value] ?? 0),
            'merge' => (int) ($counts[AdifRecordStatus::DuplicateMatch->value] ?? 0),
            'skip' => (int) ($counts[AdifRecordStatus::Skipped->value] ?? 0),
            'invalid' => (int) ($counts[AdifRecordStatus::Invalid->value] ?? 0),
        ];
    }

    public function toggleSkip(int $recordId): void
    {
        $import = $this->findOwnedImport();

        if ($import->status !== AdifImportStatus::PendingReview) {
            return;
        }

        $record = AdifImportRecord::findOrFail($recordId);

        if ($record->adif_import_id !== $import->id) {
            return;
        }

        if ($record->status === AdifRecordStatus::Skipped) {
            // A skipped record that failed validation keeps its errors in
            // notes and can't be included again.
            if ($record->notes !== null && $record->notes !== '') {
                return;
            }
            $record->update(['status' => AdifRecordStatus::Ready]);
        } else {
            $record->update(['status' => AdifRecordStatus::Skipped]);
        }

        $this->matchSummary = $this->summarizeRecords($import);
    }

    /**
     * Skip every record that failed validation, in one query, so the import
     * can go ahead. Their errors stay in notes, so they can't be included again.
     */
    public function skipAllInvalid(): void
    {
        $import = $this->findOwnedImport();

        if ($import->status !== AdifImportStatus::PendingReview) {
            return;
        }

        $import->records()
            ->where('status', AdifRecordStatus::Invalid)
            ->update(['status' => AdifRecordStatus::Skipped, 'updated_at' => now()]);

        unset($this->importRecords, $this->hasInvalidRecords);
        $this->matchSummary = $this->summarizeRecords($import);

        if ($this->recordFilter === 'invalid') {
            $this->recordFilter = 'all';
        }

        $this->resetPage();
    }

    public function updatedRecordFilter(): void
    {
        if ($this->recordFilter !== 'all' && ! array_key_exists($this->recordFilter, self::RECORD_FILTERS)) {
            $this->recordFilter = 'all';
        }

        $this->resetPage();
    }

    #[Computed]
    public function hasInvalidRecords(): bool
    {
        if (! $this->importId) {
            return false;
        }

        return AdifImportRecord::where('adif_import_id', $this->findOwnedImport()->id)
            ->where('status', AdifRecordStatus::Invalid)
            ->exists();
    }

    /**
     * One page of the review table, narrowed by the record filter.
     */
    #[Computed]
    public function importRecords(): ?LengthAwarePaginator
    {
        if (! $this->importId) {
            return null;
        }

        $statuses = self::RECORD_FILTERS[$this->recordFilter] ?? null;

        return AdifImportRecord::query()
            ->where('adif_import_id', $this->findOwnedImport()->id)
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->with(['band:id,name', 'mode:id,name', 'section:id,code'])
            ->orderBy('qso_time')
            ->orderBy('id')
            ->paginate(self::RECORDS_PER_PAGE);
    }

    #[Computed]
    public function availableBands()
    {
        return Band::query()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function availableModes()
    {
        return Mode::query()->get();
    }

    #[Computed]
    public function availableSections()
    {
        return Section::query()->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function availableStations()
    {
        $event = app(EventContextService::class)->getContextEvent();

        return Station::query()
            ->where('event_configuration_id', $event?->eventConfiguration?->id)
            ->get();
    }

    #[Computed]
    public function availableOperators()
    {
        return User::query()->whereNotNull('call_sign')->orderBy('call_sign')->get();
    }

    #[Computed]
    public function currentImport()
    {
        return $this->importId ? $this->findOwnedImport() : null;
    }

    public function render(): View
    {
        return view('livewire.admin.adif-import')
            ->layout('components.layouts.app', ['title' => 'ADIF Import']);
    }

    /**
     * Load the wizard's import, ensuring it belongs to the current user and event.
     */
    private function findOwnedImport(): AdifImportModel
    {
        $event = app(EventContextService::class)->getContextEvent();

        return AdifImportModel::query()
            ->whereKey($this->importId)
            ->where('user_id', auth()->id())
            ->where('event_configuration_id', $event?->eventConfiguration?->id)
            ->firstOrFail();
    }

    /**
     * Drop mapping entries the user left unselected.
     *
     * @param  array<string, mixed>  $mappings
     * @return array<string, mixed>
     */
    private function withoutBlankSelections(array $mappings): array
    {
        return array_filter($mappings, fn (mixed $id): bool => $id !== null && $id !== '');
    }

    /**
     * Confirm every selected mapping points at a real record, and stations at this import's event.
     */
    private function mappingsReferenceValidRecords(AdifImportModel $import): bool
    {
        $checks = [
            [$this->bandMappings, Band::query()],
            [$this->modeMappings, Mode::query()],
            [$this->sectionMappings, Section::query()],
            [$this->stationMappings, Station::query()->where('event_configuration_id', $import->event_configuration_id)],
            [$this->operatorMappings, User::query()],
        ];

        foreach ($checks as [$mappings, $query]) {
            if ($mappings === []) {
                continue;
            }

            foreach ($mappings as $id) {
                if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                    return false;
                }
            }

            $ids = array_unique(array_map('intval', array_values($mappings)));

            if ($query->whereKey($ids)->count() !== count($ids)) {
                return false;
            }
        }

        return true;
    }

    private function parseQsoTime(?string $date, ?string $time): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        $timeStr = $time ?? '000000';
        $timeStr = str_pad($timeStr, 6, '0', STR_PAD_RIGHT);

        try {
            return Carbon::createFromFormat('YmdHis', $date.$timeStr, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{version: int, header: array<string, string>, records: array<int, array<string, string>>, errors: array<string>}  $parseResult
     * @return array{total: int, date_range: string, bands: array<string>, modes: array<string>, stations: array<string>}
     */
    private function buildUploadSummary(AdifImportModel $import, array $parseResult): array
    {
        $records = $import->records()->get(['qso_time', 'band_name', 'mode_name', 'station_identifier']);

        $times = $records->pluck('qso_time')->filter();
        $dateRange = $times->isEmpty()
            ? 'Unknown'
            : $times->min()->format('Y-m-d H:i').' to '.$times->max()->format('Y-m-d H:i').' UTC';

        return [
            'total' => count($parseResult['records']),
            'date_range' => $dateRange,
            'bands' => $records->pluck('band_name')->unique()->filter()->values()->toArray(),
            'modes' => $records->pluck('mode_name')->unique()->filter()->values()->toArray(),
            'stations' => $records->pluck('station_identifier')->unique()->filter()->values()->toArray(),
        ];
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'adifFile' => 'ADIF file',
        ];
    }
}
