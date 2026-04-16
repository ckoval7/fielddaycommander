<?php

namespace App\Livewire\Logbook;

use App\Models\Band;
use App\Models\Event;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\EventContextService;
use App\Services\LogbookQueryBuilder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LogbookBrowser extends Component
{
    use WithPagination;

    #[Url]
    public array $bandIds = [];

    #[Url]
    public array $modeIds = [];

    #[Url]
    public array $stationIds = [];

    #[Url]
    public array $operatorIds = [];

    #[Url]
    public ?string $timeFrom = null;

    #[Url]
    public ?string $timeTo = null;

    #[Url]
    public ?string $callsignSearch = null;

    #[Url]
    public array $sectionIds = [];

    #[Url]
    public ?string $showDuplicates = null;

    #[Url]
    public ?string $showTranscribed = null;

    #[Url]
    public ?string $showGota = null;

    #[Url]
    public ?string $showDeleted = null;

    public ?int $eventConfigurationId = null;

    public int $perPage = 50;

    /** @var array<int> */
    public array $selectedIds = [];

    public function mount(): void
    {
        $service = app(EventContextService::class);
        $activeEvent = $service->getContextEvent();

        // No active event — fall back to most recently ended event (handles grace period)
        if (! $activeEvent) {
            $activeEvent = Event::query()
                ->with('eventConfiguration')
                ->where('end_time', '<=', appNow())
                ->orderByDesc('end_time')
                ->first();
        }

        if (! $activeEvent || ! $activeEvent->eventConfiguration) {
            $this->eventConfigurationId = null;

            return;
        }

        $this->eventConfigurationId = $activeEvent->eventConfiguration->id;
    }

    public function resetFilters(): void
    {
        $this->reset([
            'bandIds',
            'modeIds',
            'stationIds',
            'operatorIds',
            'timeFrom',
            'timeTo',
            'callsignSearch',
            'sectionIds',
            'showDuplicates',
            'showTranscribed',
            'showGota',
            'showDeleted',
            'selectedIds',
        ]);
        $this->resetPage();
    }

    public function deselectAll(): void
    {
        $this->selectedIds = [];
    }

    public function updatedBandIds(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedModeIds(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedStationIds(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedOperatorIds(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedTimeFrom(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedTimeTo(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedCallsignSearch(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedSectionIds(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedShowDuplicates(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedShowTranscribed(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedShowGota(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedShowDeleted(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    #[On('contact-updated')]
    #[On('contact-deleted')]
    #[On('contact-restored')]
    public function refreshContacts(): void
    {
        $this->selectedIds = [];
        unset($this->contacts);
        unset($this->stats);
    }

    #[Computed]
    public function contacts(): CursorPaginator
    {
        if (! $this->eventConfigurationId) {
            return collect()->cursorPaginate($this->perPage);
        }

        $queryBuilder = new LogbookQueryBuilder;

        $filters = [
            'event_configuration_id' => $this->eventConfigurationId,
            'band_ids' => $this->bandIds,
            'mode_ids' => $this->modeIds,
            'station_ids' => $this->stationIds,
            'operator_ids' => $this->operatorIds,
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'callsign' => $this->callsignSearch,
            'section_ids' => $this->sectionIds,
            'duplicate_filter' => $this->showDuplicates,
            'transcribed_filter' => $this->showTranscribed,
            'gota_filter' => $this->showGota,
            'deleted_filter' => $this->showDeleted,
        ];

        $query = $queryBuilder->applyFilters($filters);

        return $query->cursorPaginate($this->perPage);
    }

    #[Computed]
    public function bands()
    {
        return Band::orderBy('name')->get();
    }

    #[Computed]
    public function modes()
    {
        return Mode::orderBy('name')->get();
    }

    #[Computed]
    public function stations()
    {
        if (! $this->eventConfigurationId) {
            return collect();
        }

        return Station::where('event_configuration_id', $this->eventConfigurationId)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function operators()
    {
        return User::orderBy('call_sign')
            ->get()
            ->map(function (User $user) {
                $user->display_name = $user->first_name
                    ? "{$user->first_name}, {$user->call_sign}"
                    : $user->call_sign;

                return $user;
            });
    }

    #[Computed]
    public function sections()
    {
        return Section::orderBy('code')->get()->map(function (Section $section) {
            $section->display_name = "{$section->code} – {$section->name}";

            return $section;
        });
    }

    #[Computed]
    public function stats(): array
    {
        if (! $this->eventConfigurationId) {
            return [
                'total_qsos' => 0,
                'total_points' => 0,
                'unique_sections' => 0,
                'by_band' => [],
                'by_mode' => [],
            ];
        }

        $queryBuilder = new LogbookQueryBuilder;

        $filters = [
            'event_configuration_id' => $this->eventConfigurationId,
            'band_ids' => $this->bandIds,
            'mode_ids' => $this->modeIds,
            'station_ids' => $this->stationIds,
            'operator_ids' => $this->operatorIds,
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'callsign' => $this->callsignSearch,
            'section_ids' => $this->sectionIds,
            'duplicate_filter' => $this->showDuplicates,
            'transcribed_filter' => $this->showTranscribed,
            'gota_filter' => $this->showGota,
            'deleted_filter' => $this->showDeleted,
        ];

        $query = $queryBuilder->applyFilters($filters)->reorder();

        // Get aggregated stats
        $totalQsos = $query->count();
        $totalPoints = $query->sum('points');
        $uniqueSections = $query->distinct('section_id')->count('section_id');

        // Stats by band
        $byBand = $queryBuilder->applyFilters($filters)->reorder()
            ->select('band_id', \DB::raw('count(*) as count'))
            ->groupBy('band_id')
            ->with('band')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->band?->name ?? 'Unknown' => $item->count];
            })
            ->toArray();

        // Stats by mode
        $byMode = $queryBuilder->applyFilters($filters)->reorder()
            ->select('mode_id', \DB::raw('count(*) as count'))
            ->groupBy('mode_id')
            ->with('mode')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->mode?->name ?? 'Unknown' => $item->count];
            })
            ->toArray();

        return [
            'total_qsos' => $totalQsos,
            'total_points' => $totalPoints,
            'unique_sections' => $uniqueSections,
            'by_band' => $byBand,
            'by_mode' => $byMode,
        ];
    }

    public function render(): View
    {
        return view('livewire.logbook.logbook-browser')->layout('layouts.app');
    }
}
