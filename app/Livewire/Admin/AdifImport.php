<?php

namespace App\Livewire\Admin;

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Models\AdifImport as AdifImportModel;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifDuplicateMatcherService;
use App\Services\AdifFieldMapperService;
use App\Services\AdifImportService;
use App\Services\AdifParserService;
use App\Services\EventContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class AdifImport extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public ?int $importId = null;

    /** @var TemporaryUploadedFile|null */
    public $adifFile = null;

    /** @var array{total: int, date_range: string, bands: array<string>, modes: array<string>, stations: array<string>} */
    public array $uploadSummary = [];

    /** @var array{unmapped_bands: array<string>, unmapped_modes: array<string>, unmapped_sections: array<string>, unmapped_stations: array<string>, unmapped_operators: array<string>} */
    public array $mappingReport = [];

    /** @var array<string, array{exchange_class?: array<string>, section_code?: array<string>}> */
    public array $inconsistencies = [];

    /** @var array{new: int, merge: int, skip: int} */
    public array $matchSummary = [];

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
            $this->addError('adifFile', 'No QSO records found in the file.');

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

        foreach ($result['records'] as $record) {
            $qsoTime = $this->parseQsoTime($record['QSO_DATE'] ?? null, $record['TIME_ON'] ?? null);

            AdifImportRecord::create([
                'adif_import_id' => $import->id,
                'raw_data' => $record,
                'callsign' => isset($record['CALL']) ? strtoupper(trim($record['CALL'])) : null,
                'qso_time' => $qsoTime,
                'band_name' => $record['BAND'] ?? null,
                'mode_name' => $record['MODE'] ?? null,
                'section_code' => $record['ARRL_SECT'] ?? null,
                'exchange_class' => $record['APP_N1MM_EXCHANGE1'] ?? $record['CLASS'] ?? null,
                'station_identifier' => $record['APP_N1MM_NETBIOSNAME'] ?? $record['STATION_CALLSIGN'] ?? null,
                'operator_callsign' => $record['OPERATOR'] ?? null,
                'status' => AdifRecordStatus::Pending,
            ]);
        }

        $mapper = new AdifFieldMapperService;
        $this->mappingReport = $mapper->autoMap($import);
        $this->inconsistencies = $mapper->detectInconsistencies($import);

        $this->uploadSummary = $this->buildUploadSummary($import, $result);
        $this->step = 2;
    }

    public function applyMappingsAndContinue(): void
    {
        $import = AdifImportModel::findOrFail($this->importId);
        $mapper = new AdifFieldMapperService;

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

        $matcher = new AdifDuplicateMatcherService;
        $this->matchSummary = $matcher->match($import);

        $this->step = 3;
    }

    public function executeImport(): void
    {
        $import = AdifImportModel::findOrFail($this->importId);
        $service = new AdifImportService;

        try {
            $service->import($import);
            $import->refresh();
            $this->importStatus = 'completed';
        } catch (\Throwable) {
            $import->refresh();
            $this->importStatus = 'failed';
        }
    }

    #[Computed]
    public function importRecords()
    {
        if (! $this->importId) {
            return collect();
        }

        return AdifImportRecord::where('adif_import_id', $this->importId)
            ->orderBy('qso_time')
            ->get();
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
        return $this->importId ? AdifImportModel::find($this->importId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.adif-import')
            ->layout('components.layouts.app');
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
        $records = $import->records;

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
}
