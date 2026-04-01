<?php

namespace App\Livewire\Logging;

use App\Models\Band;
use App\Models\Event;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StationSelect extends Component
{
    use AuthorizesRequests;

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

        // Check if user already has an active session
        $activeSession = OperatingSession::query()
            ->active()
            ->forUser(auth()->id())
            ->with('station')
            ->first();

        if ($activeSession) {
            $this->redirect(route('logging.session', $activeSession), navigate: true);
        }
    }

    #[Computed]
    public function activeEvent(): ?Event
    {
        $service = app(\App\Services\EventContextService::class);
        $contextEvent = $service->getContextEvent();

        if (! $contextEvent) {
            return null;
        }

        $status = $service->getGracePeriodStatus($contextEvent);

        // Allow logging only during active events — use transcription for post-event entry
        if ($status === 'active') {
            return $contextEvent->load('eventConfiguration.stations.operatingSessions.operator');
        }

        return null;
    }

    #[Computed]
    public function stations()
    {
        $event = $this->activeEvent;
        if (! $event?->eventConfiguration) {
            return collect();
        }

        return $event->eventConfiguration->stations()
            ->with([
                'primaryRadio.bands',
                'operatingSessions' => function ($query) {
                    $query->active()->with(['operator', 'band', 'mode'])->latest();
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Station $station) {
                $activeSession = $station->operatingSessions->first();
                $status = 'available';

                if ($activeSession) {
                    $lastActivity = $activeSession->contacts()
                        ->latest('qso_time')
                        ->value('qso_time');

                    $idleThreshold = appNow()->subMinutes(30);
                    $referenceTime = $lastActivity ? \Carbon\Carbon::parse($lastActivity) : $activeSession->start_time;

                    $status = $referenceTime->lt($idleThreshold) ? 'idle' : 'occupied';
                }

                $station->setAttribute('computed_status', $status);
                $station->setAttribute('active_session', $activeSession);

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
                'message' => 'This station has no radio assigned — band compatibility cannot be verified.',
            ];
        }

        $radioBands = $station->primaryRadio->bands;

        if ($radioBands->isEmpty()) {
            return [
                'type' => 'info',
                'message' => "This station's radio has no band information configured — compatibility cannot be verified.",
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
                'message' => "Selected band ({$selectedBand->name}) is not supported by this station's radio ({$radioName}). Supported bands: {$supportedList}.",
            ];
        }

        return null;
    }

    public function selectStation(int $stationId): void
    {
        $station = $this->stations->firstWhere('id', $stationId);
        if (! $station) {
            return;
        }

        if ($station->computed_status === 'available') {
            $this->selectedStationId = $stationId;
            $this->showSetupModal = true;
        } elseif ($station->computed_status === 'idle') {
            $this->takeoverStationId = $stationId;
            $this->showTakeoverModal = true;
        }
        // occupied stations are not selectable
    }

    public function startSession(): void
    {
        $this->validate([
            'selectedStationId' => 'required|exists:stations,id',
            'selectedBandId' => 'required|exists:bands,id',
            'selectedModeId' => 'required|exists:modes,id',
            'powerWatts' => 'required|integer|min:1|max:1500',
        ]);

        $station = Station::find($this->selectedStationId);

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

        // End the existing session
        OperatingSession::query()
            ->active()
            ->forStation($this->takeoverStationId)
            ->update(['end_time' => appNow()]);

        // Open setup modal for the new session
        $this->selectedStationId = $this->takeoverStationId;
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
            ->layout('layouts.app');
    }
}
