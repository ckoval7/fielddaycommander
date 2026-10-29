<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Logbook;

use App\Livewire\Dashboard\Widgets\Concerns\ListensForBroadcasts;
use App\Models\Band;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\LogbookFilters;
use App\Support\BroadcastChannels;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LogbookBrowser extends Component
{
    use ListensForBroadcasts;
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

    #[Url]
    public ?string $needsAttention = null;

    /**
     * Event to browse, when a link names one (e.g. the reports page's Needs
     * Attention link); otherwise the current event context is used.
     */
    #[Url(as: 'event')]
    public ?int $eventId = null;

    public ?int $eventConfigurationId = null;

    public int $perPage = 50;

    /** @var array<int> */
    public array $selectedIds = [];

    public function mount(): void
    {
        $activeEvent = LogbookFilters::resolveEvent($this->eventId);

        $this->eventConfigurationId = $activeEvent?->eventConfiguration?->id;
    }

    public function resetFilters(): void
    {
        $this->reset([...LogbookFilters::FILTER_KEYS, 'selectedIds']);
        $this->resetPage();
    }

    public function deselectAll(): void
    {
        $this->selectedIds = [];
    }

    public function updated(string $property): void
    {
        if (in_array($property, LogbookFilters::FILTER_KEYS, true)) {
            $this->selectedIds = [];
            $this->resetPage();
        }
    }

    /**
     * Refresh when contacts are logged, edited or deleted anywhere in the event.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $eventId = $this->eventConfigurationId !== null
            ? EventConfiguration::query()->whereKey($this->eventConfigurationId)->value('event_id')
            : null;

        if ($eventId === null || ! $this->canListenForBroadcasts()) {
            return [];
        }

        return BroadcastChannels::contactChangeListeners($eventId, 'refreshFromBroadcast');
    }

    /**
     * Re-read the page for a change made elsewhere, keeping the operator's
     * row selection.
     */
    public function refreshFromBroadcast(): void
    {
        unset($this->contacts);
        unset($this->stats);
        unset($this->attentionReasons);
    }

    #[On('contact-updated')]
    #[On('contact-deleted')]
    #[On('contact-restored')]
    public function refreshContacts(): void
    {
        $this->selectedIds = [];
        unset($this->contacts);
        unset($this->stats);
        unset($this->attentionReasons);
    }

    #[Computed]
    public function activeFilterCount(): int
    {
        return count($this->activeFilterPills);
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    #[Computed]
    public function activeFilterPills(): array
    {
        $pills = [];

        $idFilters = [
            'bandIds' => ['key' => 'bandIds', 'prefix' => 'Band'],
            'modeIds' => ['key' => 'modeIds', 'prefix' => 'Mode'],
            'stationIds' => ['key' => 'stationIds', 'prefix' => 'Station'],
            'operatorIds' => ['key' => 'operatorIds', 'prefix' => 'Logger'],
            'sectionIds' => ['key' => 'sectionIds', 'prefix' => 'Section'],
        ];

        foreach ($idFilters as $property => $config) {
            $count = count($this->{$property});
            if ($count > 0) {
                $pills[] = ['key' => $config['key'], 'label' => "{$config['prefix']}: {$count} selected"];
            }
        }
        if ($this->callsignSearch !== null && $this->callsignSearch !== '') {
            $pills[] = ['key' => 'callsignSearch', 'label' => "Callsign: {$this->callsignSearch}"];
        }
        if ($this->timeFrom !== null || $this->timeTo !== null) {
            $label = match (true) {
                $this->timeFrom !== null && $this->timeTo !== null => "Time: {$this->timeFrom} – {$this->timeTo}",
                $this->timeFrom !== null => "Time from: {$this->timeFrom}",
                default => "Time to: {$this->timeTo}",
            };
            $pills[] = ['key' => 'timeRange', 'label' => $label];
        }

        $radioLabels = [
            'showDuplicates' => ['prefix' => 'Dupes', 'values' => ['exclude' => 'Exclude', 'only' => 'Only']],
            'showTranscribed' => ['prefix' => 'Transcribed', 'values' => ['only' => 'Only']],
            'showGota' => ['prefix' => 'GOTA', 'values' => ['only' => 'Only', 'exclude' => 'Exclude']],
            'showDeleted' => ['prefix' => 'Deleted', 'values' => ['include' => 'Include', 'only' => 'Only']],
            'needsAttention' => ['prefix' => 'Needs attention', 'values' => ['only' => 'Only']],
        ];

        foreach ($radioLabels as $property => $config) {
            $value = $this->{$property};
            if ($value !== null && $value !== '') {
                $label = $config['values'][$value] ?? ucfirst($value);
                $pills[] = ['key' => $property, 'label' => "{$config['prefix']}: {$label}"];
            }
        }

        return $pills;
    }

    public function removeFilter(string $key): void
    {
        match ($key) {
            'bandIds' => $this->bandIds = [],
            'modeIds' => $this->modeIds = [],
            'stationIds' => $this->stationIds = [],
            'operatorIds' => $this->operatorIds = [],
            'sectionIds' => $this->sectionIds = [],
            'callsignSearch' => $this->callsignSearch = null,
            'timeRange' => [$this->timeFrom = null, $this->timeTo = null],
            'showDuplicates' => $this->showDuplicates = null,
            'showTranscribed' => $this->showTranscribed = null,
            'showGota' => $this->showGota = null,
            'showDeleted' => $this->showDeleted = null,
            'needsAttention' => $this->needsAttention = null,
            default => null,
        };

        $this->selectedIds = [];
        $this->resetPage();
    }

    /**
     * The filters currently applied to the list.
     */
    public function filters(): LogbookFilters
    {
        return LogbookFilters::fromArray(
            collect(LogbookFilters::FILTER_KEYS)->mapWithKeys(fn (string $key): array => [$key => $this->{$key}])->all()
        );
    }

    /**
     * Link to a CSV of exactly the contacts listed: the filters on screen and
     * the event being shown. Built from component state, since during a
     * Livewire update the request is /livewire/update, not the page URL.
     */
    #[Computed]
    public function exportUrl(): string
    {
        $eventId = $this->eventConfigurationId !== null
            ? EventConfiguration::query()->whereKey($this->eventConfigurationId)->value('event_id')
            : null;

        return route('logbook.export', array_filter([
            'event' => $eventId,
            ...$this->filters()->toQueryParameters(),
        ], fn (mixed $value): bool => $value !== null));
    }

    #[Computed]
    public function contacts(): CursorPaginator
    {
        if (! $this->eventConfigurationId) {
            return collect()->cursorPaginate($this->perPage);
        }

        return $this->filters()->query($this->eventConfigurationId)->cursorPaginate($this->perPage);
    }

    /**
     * Why each contact on this page earns no credit, keyed by contact id.
     *
     * Empty unless the event's rulebook withholds credit from some contacts
     * (e.g. WFD 2027 WSJT-mode and satellite contacts). The rules are resolved
     * once per page; contacts arrive with their band already loaded.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function noCreditReasons(): array
    {
        if (! $this->eventConfigurationId) {
            return [];
        }

        $rules = EventConfiguration::query()->find($this->eventConfigurationId)?->contactCreditRules();

        if ($rules === null) {
            return [];
        }

        $reasons = [];

        foreach ($this->contacts as $contact) {
            $reason = $rules->noCreditReason($contact);

            if ($reason !== null) {
                $reasons[$contact->id] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * Why each contact on this page needs attention (missing band or mode,
     * or logged outside the event window), keyed by contact id.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function attentionReasons(): array
    {
        // Without a selected event each contact is judged by its own event.
        $event = $this->eventConfigurationId
            ? EventConfiguration::query()->with('event')->find($this->eventConfigurationId)?->event
            : null;
        $reasons = [];

        foreach ($this->contacts as $contact) {
            $contactReasons = $contact->attentionReasons($event);

            if ($contactReasons !== []) {
                $reasons[$contact->id] = $contactReasons;
            }
        }

        return $reasons;
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
            $section->display_name = "{$section->code}: {$section->name}";

            return $section;
        });
    }

    /**
     * Summary figures for the filtered logbook, counted the way the scoring
     * page counts them: QSOs and the band and mode tallies leave out dupes,
     * and QSO points also leave out any contact the rulebook gives no credit.
     * The dupes and the GOTA bonus the GOTA contacts earn on top of their QSO
     * points are reported alongside.
     *
     * @return array{total_qsos: int, duplicate_count: int, total_points: int, gota_points: int, unique_sections: int, by_band: array<string, int>, by_mode: array<string, int>}
     */
    #[Computed]
    public function stats(): array
    {
        if (! $this->eventConfigurationId) {
            return [
                'total_qsos' => 0,
                'duplicate_count' => 0,
                'total_points' => 0,
                'gota_points' => 0,
                'unique_sections' => 0,
                'by_band' => [],
                'by_mode' => [],
            ];
        }

        $filters = $this->filters();
        $counted = fn () => $filters->query($this->eventConfigurationId)->reorder()->notDuplicate();

        $totalQsos = $counted()->count();
        $duplicateCount = $filters->query($this->eventConfigurationId)->reorder()->duplicatesOnly()->count();
        $uniqueSections = $counted()->distinct('section_id')->count('section_id');

        $config = EventConfiguration::find($this->eventConfigurationId);
        $pointsQuery = $counted();
        $config?->contactCreditRules()?->constrainToCreditable($pointsQuery);
        $totalPoints = (int) $pointsQuery->sum('points');
        $gotaPoints = $counted()->where('is_gota_contact', true)->count() * ($config?->gotaBonusPointsPerContact() ?? 0);

        $byBand = $counted()
            ->select('band_id', \DB::raw('count(*) as count'))
            ->groupBy('band_id')
            ->with('band')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->band?->name ?? 'Unknown' => $item->count];
            })
            ->toArray();

        $byMode = $counted()
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
            'duplicate_count' => $duplicateCount,
            'total_points' => $totalPoints,
            'gota_points' => $gotaPoints,
            'unique_sections' => $uniqueSections,
            'by_band' => $byBand,
            'by_mode' => $byMode,
        ];
    }

    public function render(): View
    {
        return view('livewire.logbook.logbook-browser')->layout('layouts.app', ['title' => 'Logbook']);
    }
}
