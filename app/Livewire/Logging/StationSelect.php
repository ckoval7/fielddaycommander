<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Logging;

use App\Models\Band;
use App\Models\Event;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Services\EventContextService;
use App\Services\LoggingWarningService;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StationSelect extends Component
{
    use AuthorizesRequests;

    /**
     * Highest session power accepted when the radio has no rating: the US
     * amateur legal limit.
     */
    private const LEGAL_LIMIT_WATTS = 1500;

    public bool $showSetupModal = false;

    public ?int $selectedStationId = null;

    public ?int $selectedBandId = null;

    public ?int $selectedModeId = null;

    public ?int $powerWatts = 100;

    public bool $isSupervisedSession = false;

    public bool $showTakeoverModal = false;

    public ?int $takeoverStationId = null;

    public function mount(): void
    {
        $this->authorize('log-contacts');

        // Check if user already has an active non-external session
        $activeSession = OperatingSession::query()
            ->active()
            ->forUser(auth()->id())
            ->whereNull('external_source')
            ->with('station')
            ->first();

        if ($activeSession && StationMode::allowsStation($activeSession->station_id)) {
            $this->redirect(route('logging.session', $activeSession), navigate: true);

            return;
        }

        // A station-mode browser goes straight to setup for its bound station.
        if (StationMode::isActive() && $this->stations->isNotEmpty()) {
            $this->selectStation($this->stations->first()->id);
        }
    }

    #[Computed]
    public function activeEvent(): ?Event
    {
        $service = app(EventContextService::class);
        $contextEvent = $service->getContextEvent();

        if (! $contextEvent) {
            return null;
        }

        $status = $service->getGracePeriodStatus($contextEvent);

        // Allow session setup during active events or within 15 minutes of start
        $withinPreStartWindow = $contextEvent->start_time
            && $contextEvent->start_time->copy()->subMinutes(15)->lte(appNow())
            && $contextEvent->start_time->gt(appNow());

        if ($status !== 'active' && ! $withinPreStartWindow) {
            return null;
        }

        return $contextEvent->load('eventConfiguration.stations.operatingSessions.operator');
    }

    #[Computed]
    public function stations()
    {
        $event = $this->activeEvent;
        if (! $event?->eventConfiguration) {
            return collect();
        }

        return $event->eventConfiguration->stations()
            ->when(StationMode::isActive(), fn ($query) => $query->whereKey(StationMode::boundStationId()))
            ->with([
                'primaryRadio.bands',
                'additionalEquipment.bands',
                'operatingSessions' => function ($query) {
                    $query->active()->with(['operator', 'band', 'mode'])->latest();
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Station $station) {
                $activeSession = $station->operatingSessions->first();
                $isExternal = $activeSession?->external_source !== null;

                $station->setAttribute('computed_status', $station->operatingStatus());
                $station->setAttribute('active_session', $activeSession);
                $station->setAttribute('is_external_session', $isExternal);

                return $station;
            });
    }

    #[Computed]
    public function bands()
    {
        return Band::query()->allowedForFieldDay()->ordered()->get();
    }

    #[Computed]
    public function modes()
    {
        return Mode::all();
    }

    #[Computed]
    public function bandWarning(): ?array
    {
        if (! $this->selectedStationId || ! $this->selectedBandId) {
            return null;
        }

        $station = $this->stations->firstWhere('id', $this->selectedStationId);
        if (! $station) {
            return null;
        }

        if (! $station->primaryRadio) {
            return [
                'type' => 'info',
                'message' => 'No radio assigned. Band compatibility cannot be checked.',
            ];
        }

        $radioBands = $station->primaryRadio->bands;

        if ($radioBands->isEmpty()) {
            return [
                'type' => 'info',
                'message' => "No bands are configured for this station's radio. Band compatibility cannot be checked.",
            ];
        }

        if (! $radioBands->contains('id', $this->selectedBandId)) {
            $selectedBand = $this->bands->firstWhere('id', $this->selectedBandId);
            if (! $selectedBand) {
                return null;
            }
            $radioName = trim($station->primaryRadio->make.' '.$station->primaryRadio->model);
            $supportedList = $radioBands->pluck('name')->join(', ');

            return [
                'type' => 'warning',
                'message' => "Selected band ({$selectedBand->name}) is outside this radio's coverage. The {$radioName} covers {$supportedList}.",
            ];
        }

        $supportedBands = $this->stationSupportedBands;
        if ($supportedBands !== null && ! $supportedBands->contains('id', $this->selectedBandId)) {
            $selectedBand = $this->bands->firstWhere('id', $this->selectedBandId);
            if (! $selectedBand) {
                return null;
            }

            return [
                'type' => 'warning',
                'message' => "Selected band ({$selectedBand->name}) is not covered by any antenna at this station.",
            ];
        }

        return null;
    }

    #[Computed]
    public function bandModeConflictWarning(): ?string
    {
        $station = $this->selectedStationId ? Station::find($this->selectedStationId) : null;

        if (! $station) {
            return null;
        }

        return app(LoggingWarningService::class)->bandModeConflictWarning(
            $station,
            $this->selectedBandId,
            $this->selectedModeId,
        );
    }

    #[Computed]
    public function stationSupportedBands(): ?Collection
    {
        $station = $this->selectedStationId
            ? $this->stations->firstWhere('id', $this->selectedStationId)
            : null;

        if (! $station?->primaryRadio) {
            return null;
        }

        $antennas = $station->additionalEquipment->where('type', 'antenna');
        if ($antennas->isEmpty()) {
            return null;
        }

        $radioBandIds = $station->primaryRadio->bands->pluck('id');
        $antennaBandIds = $antennas->flatMap(fn ($antenna) => $antenna->bands->pluck('id'))->unique();
        $intersectingBandIds = $radioBandIds->intersect($antennaBandIds);

        return $station->primaryRadio->bands
            ->filter(fn ($band) => $intersectingBandIds->contains($band->id))
            ->values();
    }

    public function selectStation(int $stationId): void
    {
        $station = $this->stations->firstWhere('id', $stationId);
        if (! $station) {
            return;
        }

        if ($station->computed_status === 'available') {
            $this->selectedStationId = $stationId;
            $this->powerWatts = $this->defaultPowerWattsFor($station);
            $this->showSetupModal = true;
        } elseif ($station->computed_status === 'idle') {
            $this->takeoverStationId = $stationId;
            $this->showTakeoverModal = true;
        }
        // occupied and unavailable stations are not selectable
    }

    private function defaultPowerWattsFor(Station $station): int
    {
        $radioLimit = $station->primaryRadio?->power_output_watts;

        if ($radioLimit !== null && $radioLimit > 0) {
            return min(100, $radioLimit);
        }

        return 100;
    }

    /**
     * Rated output of the station's primary radio, or null when it has none
     * recorded. A session can't run more power than the radio is rated for.
     */
    private function radioRatedPowerWatts(?Station $station): ?int
    {
        $rating = $station?->primaryRadio?->power_output_watts;

        return $rating !== null && $rating > 0 ? (int) $rating : null;
    }

    /**
     * Most power the setup form accepts for the selected station: the radio's
     * rating, or the legal limit when the radio has no rating.
     */
    #[Computed]
    public function maxPowerWatts(): int
    {
        $station = $this->selectedStationId
            ? $this->stations->firstWhere('id', $this->selectedStationId)
            : null;

        return $this->radioRatedPowerWatts($station) ?? self::LEGAL_LIMIT_WATTS;
    }

    public function startSession(): void
    {
        $station = Station::with('primaryRadio')->find($this->selectedStationId);
        $radioRating = $this->radioRatedPowerWatts($station);

        $this->validate([
            'selectedStationId' => 'required|exists:stations,id',
            'selectedBandId' => 'required|exists:bands,id',
            'selectedModeId' => 'required|exists:modes,id',
            'powerWatts' => 'required|integer|min:1|max:'.($radioRating ?? self::LEGAL_LIMIT_WATTS),
        ], $radioRating === null ? [] : [
            'powerWatts.max' => "This station's radio is rated for {$radioRating} W. Enter {$radioRating} W or less.",
        ]);

        if (StationMode::isActive() && $station?->id !== StationMode::boundStationId()) {
            $this->addError('selectedStationId', 'This browser can only log for its assigned station.');

            return;
        }

        if ($station?->isUnavailable()) {
            $this->addError('selectedStationId', "{$station->name} has been marked unavailable by a station captain.");

            return;
        }

        // A walk-up operator has left any other station they were running.
        if (StationMode::isActive()) {
            OperatingSession::query()
                ->active()
                ->forUser(auth()->id())
                ->whereNull('external_source')
                ->update(['end_time' => appNow()]);
        }

        // Per FD Rule 6.9: no two transmitters on the same band-mode at the same time.
        // Exception: GOTA stations may operate on any band-mode (Rule 4.1).
        if (! $station?->is_gota) {
            $conflictingSession = OperatingSession::query()
                ->active()
                ->where('band_id', $this->selectedBandId)
                ->where('mode_id', $this->selectedModeId)
                ->whereHas('station', fn ($q) => $q->where('is_gota', false))
                ->with('station')
                ->first();

            if ($conflictingSession) {
                $stationName = $conflictingSession->station?->name ?? 'another station';
                $this->addError('selectedBandId', "This band/mode combination is already in use at {$stationName}.");

                return;
            }
        }

        $session = OperatingSession::create([
            'station_id' => $this->selectedStationId,
            'operator_user_id' => auth()->id(),
            'band_id' => $this->selectedBandId,
            'mode_id' => $this->selectedModeId,
            'power_watts' => $this->powerWatts,
            'start_time' => appNow(),
            'qso_count' => 0,
            'is_supervised' => $station?->is_gota ? $this->isSupervisedSession : false,
        ]);

        $this->redirect(route('logging.session', $session), navigate: true);
    }

    public function confirmTakeover(): void
    {
        $this->validate([
            'takeoverStationId' => 'required|exists:stations,id',
        ]);

        if (StationMode::isActive() && $this->takeoverStationId !== StationMode::boundStationId()) {
            $this->cancelTakeover();

            return;
        }

        if (Station::find($this->takeoverStationId)?->isUnavailable()) {
            $this->cancelTakeover();

            return;
        }

        // End the existing session
        OperatingSession::query()
            ->active()
            ->forStation($this->takeoverStationId)
            ->update(['end_time' => appNow()]);

        // Open setup modal for the new session
        $this->selectedStationId = $this->takeoverStationId;
        $station = $this->stations->firstWhere('id', $this->takeoverStationId);
        if ($station) {
            $this->powerWatts = $this->defaultPowerWattsFor($station);
        }
        $this->showTakeoverModal = false;
        $this->showSetupModal = true;
    }

    public function cancelSetup(): void
    {
        $this->showSetupModal = false;
        $this->selectedStationId = null;
        $this->selectedBandId = null;
        $this->selectedModeId = null;
        $this->powerWatts = 100;
        $this->isSupervisedSession = false;
    }

    public function cancelTakeover(): void
    {
        $this->showTakeoverModal = false;
        $this->takeoverStationId = null;
    }

    public function render(): View
    {
        return view('livewire.logging.station-select')
            ->layout('layouts.app', ['title' => 'Logging']);
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'selectedStationId' => 'station',
            'selectedBandId' => 'band',
            'selectedModeId' => 'mode',
            'powerWatts' => 'power',
            'takeoverStationId' => 'station',
        ];
    }
}
