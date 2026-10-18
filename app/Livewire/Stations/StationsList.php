<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Stations;

use App\Enums\StationUnavailableReason;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Station;
use App\Models\StationDevice;
use App\Services\EventContextService;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class StationsList extends Component
{
    use AuthorizesRequests, WithPagination;

    public ?int $eventFilter = null;

    public bool $showUnavailableModal = false;

    public ?int $readinessStationId = null;

    public ?string $unavailableReason = null;

    public string $unavailableNote = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Station::class);

        // Determine default event to show
        $defaultEvent = $this->getDefaultEvent();
        if ($defaultEvent) {
            $this->eventFilter = $defaultEvent->id;
        }
    }

    /**
     * Get the default event to display (active, upcoming, or most recent).
     */
    private function getDefaultEvent(): ?Event
    {
        // 1. Try context event (session-overridden or active event)
        $contextEvent = app(EventContextService::class)->getContextEvent();
        if ($contextEvent) {
            return $contextEvent;
        }

        // 2. Try next upcoming event
        $upcomingEvent = Event::upcoming()
            ->orderBy('start_time', 'asc')
            ->first();
        if ($upcomingEvent) {
            return $upcomingEvent;
        }

        // 3. Fall back to most recent past event
        return Event::completed()
            ->orderBy('end_time', 'desc')
            ->first();
    }

    /**
     * Reset to page 1 when event filter changes.
     */
    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get all events for the event filter dropdown.
     */
    #[Computed]
    public function events()
    {
        return Event::query()
            ->withoutTrashed()
            ->with('eventType')
            ->orderByDesc('start_time')
            ->get();
    }

    /**
     * Get quick stats for the selected event.
     */
    #[Computed]
    public function stats()
    {
        $empty = ['total' => 0, 'active' => 0, 'idle' => 0, 'unavailable' => 0, 'equipment_count' => 0];

        if (! $this->eventFilter) {
            return $empty;
        }

        // Find the EventConfiguration for the selected Event
        $event = Event::find($this->eventFilter);
        $eventConfigId = $event?->eventConfiguration?->id;

        if (! $eventConfigId) {
            return $empty;
        }

        $stations = Station::query()
            ->where('event_configuration_id', $eventConfigId)
            ->with(['operatingSessions' => function ($query) {
                $query->whereNull('end_time')->latest();
            }])
            ->withCount('additionalEquipment')
            ->get();

        $idle = 0;
        $active = 0;
        $unavailable = 0;

        foreach ($stations as $station) {
            $status = $station->operatingStatus();
            if ($status === 'idle') {
                $idle++;
            } elseif ($status === 'occupied') {
                $active++;
            } elseif ($status === 'unavailable') {
                $unavailable++;
            }
        }

        return [
            'total' => $stations->count(),
            'active' => $active,
            'idle' => $idle,
            'unavailable' => $unavailable,
            'equipment_count' => $stations->sum('additional_equipment_count'),
        ];
    }

    /**
     * Get the filtered and paginated stations.
     */
    #[Computed]
    public function stations()
    {
        if (! $this->eventFilter) {
            return collect();
        }

        // Find the EventConfiguration for the selected Event
        $event = Event::find($this->eventFilter);
        $eventConfigId = $event?->eventConfiguration?->id;

        if (! $eventConfigId) {
            return collect();
        }

        return Station::query()
            ->where('event_configuration_id', $eventConfigId)
            ->with([
                'primaryRadio',
                'additionalEquipment',
                'unavailableBy',
                'devices' => fn ($query) => $query->active()->latest(),
                'operatingSessions' => function ($query) {
                    $query->whereNull('end_time')->latest();
                },
            ])
            ->withCount('additionalEquipment')
            ->orderBy('name')
            ->paginate(12);
    }

    /**
     * End all active operating sessions for a station.
     */
    public function endSessions(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $this->authorize('update', $station);

        $closed = $station->operatingSessions()
            ->whereNull('end_time')
            ->update(['end_time' => now()]);

        // Clear computed caches so the card re-renders
        unset($this->stations);
        unset($this->stats);

        $this->dispatch('toast', [
            'title' => 'Sessions Ended',
            'description' => "Closed {$closed} active session(s) for {$station->name}.",
            'icon' => 'phosphor-check-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Get the unavailable reason options for the readiness modal.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function unavailableReasonOptions(): array
    {
        return StationUnavailableReason::options();
    }

    /**
     * Get the station currently targeted by the readiness modal.
     */
    #[Computed]
    public function readinessStation(): ?Station
    {
        return $this->readinessStationId ? Station::find($this->readinessStationId) : null;
    }

    /**
     * Open the modal to mark a station unavailable.
     */
    public function openUnavailableModal(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $this->authorize('updateReadiness', $station);

        $this->resetValidation();
        $this->readinessStationId = $station->id;
        $this->unavailableReason = null;
        $this->unavailableNote = '';
        unset($this->readinessStation);
        $this->showUnavailableModal = true;
    }

    /**
     * Close the readiness modal without saving.
     */
    public function cancelUnavailable(): void
    {
        $this->showUnavailableModal = false;
        $this->readinessStationId = null;
        $this->unavailableReason = null;
        $this->unavailableNote = '';
        $this->resetValidation();
    }

    /**
     * Mark the selected station unavailable, ending any active sessions.
     */
    public function markUnavailable(): void
    {
        $station = Station::findOrFail($this->readinessStationId);

        $this->authorize('updateReadiness', $station);

        $validated = $this->validate([
            'unavailableReason' => ['required', Rule::enum(StationUnavailableReason::class)],
            'unavailableNote' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'unavailableReason' => 'reason',
            'unavailableNote' => 'note',
        ]);

        $reason = StationUnavailableReason::from($validated['unavailableReason']);
        $endedSessions = $station->markUnavailable($reason, $validated['unavailableNote'], auth()->user());

        AuditLog::log(
            action: 'station.marked_unavailable',
            auditable: $station,
            newValues: [
                'reason' => $reason->value,
                'note' => $station->unavailable_note,
                'sessions_ended' => $endedSessions,
            ],
        );

        $this->cancelUnavailable();
        unset($this->stations, $this->stats);

        $description = "{$station->name} is now marked unavailable ({$reason->label()}).";
        if ($endedSessions > 0) {
            $description .= " Ended {$endedSessions} active session(s).";
        }

        $this->dispatch('toast', [
            'title' => 'Station Unavailable',
            'description' => $description,
            'icon' => 'phosphor-prohibit',
            'css' => 'alert-warning',
        ]);
    }

    /**
     * Return a station to service.
     */
    public function markAvailable(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $this->authorize('updateReadiness', $station);

        if (! $station->isUnavailable()) {
            return;
        }

        $previousReason = $station->unavailable_reason;
        $station->markAvailable();

        AuditLog::log(
            action: 'station.marked_available',
            auditable: $station,
            oldValues: ['reason' => $previousReason->value],
        );

        unset($this->stations, $this->stats);

        $this->dispatch('toast', [
            'title' => 'Station Ready',
            'description' => "{$station->name} is back in service.",
            'icon' => 'phosphor-check-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Enroll this browser as the station-mode terminal for a station, then sign the captain out.
     */
    public function enrollThisBrowser(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $this->authorize('updateReadiness', $station);

        $device = StationMode::enroll($station->id, Auth::id(), request()->userAgent());

        AuditLog::log(
            action: 'station.device_enrolled',
            auditable: $station,
            newValues: ['device_id' => $device->id],
        );

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('station-mode.landing');
    }

    /**
     * Revoke a station-mode browser so it can no longer start sessions.
     */
    public function revokeDevice(int $deviceId): void
    {
        $device = StationDevice::with('station')->findOrFail($deviceId);

        $this->authorize('updateReadiness', $device->station);

        $device->update(['revoked_at' => now()]);

        AuditLog::log(
            action: 'station.device_revoked',
            auditable: $device->station,
            newValues: ['device_id' => $device->id],
        );

        unset($this->stations);

        $this->dispatch('toast', [
            'title' => 'Browser Revoked',
            'description' => "A station-mode browser for {$device->station->name} was revoked.",
            'icon' => 'phosphor-power',
            'css' => 'alert-info',
        ]);
    }

    /**
     * Delete a station.
     */
    public function deleteStation(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $this->authorize('delete', $station);

        // Check if station has contacts
        $hasContacts = $station->contacts()->exists();

        if ($hasContacts) {
            // Soft delete if has contacts
            $station->delete();
            $this->dispatch('notify', title: 'Station Archived', description: "Station '{$station->name}' has been archived (soft deleted) because it has logged contacts.");
        } else {
            // Hard delete if no contacts
            $station->forceDelete();
            $this->dispatch('notify', title: 'Station Deleted', description: "Station '{$station->name}' has been permanently deleted.");
        }

        // Reset pagination if needed
        $this->resetPage();
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.stations.stations-list', [
            'events' => $this->events,
            'stations' => $this->stations,
            'stats' => $this->stats,
        ])->layout('layouts.app');
    }
}
