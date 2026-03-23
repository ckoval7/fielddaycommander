<?php

namespace App\Livewire\Logbook;

use App\Models\Band;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\EventContextService;
use App\Services\LogbookQueryBuilder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LogbookBrowser extends Component
{
    use WithPagination;

    #[Url]
    public array $band_ids = [];

    #[Url]
    public array $mode_ids = [];

    #[Url]
    public array $station_ids = [];

    #[Url]
    public array $operator_ids = [];

    #[Url]
    public ?string $time_from = null;

    #[Url]
    public ?string $time_to = null;

    #[Url]
    public ?string $callsign_search = null;

    #[Url]
    public array $section_ids = [];

    #[Url]
    public ?string $show_duplicates = null;

    #[Url]
    public ?string $show_transcribed = null;

    public ?int $eventConfigurationId = null;

    public int $perPage = 50;

    public function mount(): void
    {
        $service = app(EventContextService::class);
        $activeEvent = $service->getContextEvent();

        // No active event — fall back to most recently ended event (handles grace period)
        if (! $activeEvent) {
            $activeEvent = \App\Models\Event::query()
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
            'band_ids',
            'mode_ids',
            'station_ids',
            'operator_ids',
            'time_from',
            'time_to',
            'callsign_search',
            'section_ids',
            'show_duplicates',
            'show_transcribed',
        ]);
        $this->resetPage();
    }

    public function updatedBandIds(): void
    {
        $this->resetPage();
    }

    public function updatedModeIds(): void
    {
        $this->resetPage();
    }

    public function updatedStationIds(): void
    {
        $this->resetPage();
    }

    public function updatedOperatorIds(): void
    {
        $this->resetPage();
    }

    public function updatedTimeFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTimeTo(): void
    {
        $this->resetPage();
    }

    public function updatedCallsignSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSectionIds(): void
    {
        $this->resetPage();
    }

    public function updatedShowDuplicates(): void
    {
        $this->resetPage();
    }

    public function updatedShowTranscribed(): void
    {
        $this->resetPage();
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
            'band_ids' => $this->band_ids,
            'mode_ids' => $this->mode_ids,
            'station_ids' => $this->station_ids,
            'operator_ids' => $this->operator_ids,
            'time_from' => $this->time_from,
            'time_to' => $this->time_to,
            'callsign' => $this->callsign_search,
            'section_ids' => $this->section_ids,
            'duplicate_filter' => $this->show_duplicates,
            'transcribed_filter' => $this->show_transcribed,
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
            'band_ids' => $this->band_ids,
            'mode_ids' => $this->mode_ids,
            'station_ids' => $this->station_ids,
            'operator_ids' => $this->operator_ids,
            'time_from' => $this->time_from,
            'time_to' => $this->time_to,
            'callsign' => $this->callsign_search,
            'section_ids' => $this->section_ids,
            'duplicate_filter' => $this->show_duplicates,
            'transcribed_filter' => $this->show_transcribed,
        ];

        $query = $queryBuilder->applyFilters($filters);

        // Get aggregated stats
        $totalQsos = $query->count();
        $totalPoints = $query->sum('points');
        $uniqueSections = $query->distinct('section_id')->count('section_id');

        // Stats by band
        $byBand = $queryBuilder->applyFilters($filters)
            ->select('band_id', \DB::raw('count(*) as count'))
            ->groupBy('band_id')
            ->with('band')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->band?->name ?? 'Unknown' => $item->count];
            })
            ->toArray();

        // Stats by mode
        $byMode = $queryBuilder->applyFilters($filters)
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
