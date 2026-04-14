<?php

namespace App\Livewire\Stations;

use App\Models\Event;
use App\Models\Station;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class StationsList extends Component
{
    use AuthorizesRequests, WithPagination;

    public ?int $eventFilter = null;

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
        $empty = ['total' => 0, 'active' => 0, 'idle' => 0, 'equipment_count' => 0];

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

        foreach ($stations as $station) {
            $status = $station->operatingStatus();
            if ($status === 'idle') {
                $idle++;
            } elseif ($status === 'occupied') {
                $active++;
            }
        }

        return [
            'total' => $stations->count(),
            'active' => $active,
            'idle' => $idle,
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
            'icon' => 'o-check-circle',
            'css' => 'alert-success',
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
