<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Event;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EquipmentStatus extends Component
{
    public bool $tvMode = false;

    public ?Event $event = null;

    public bool $hasPermission = false;

    public function mount(bool $tvMode = false): void
    {
        $this->tvMode = $tvMode;
        $this->hasPermission = Gate::allows('view-equipment');

        if ($this->hasPermission) {
            $this->event = Event::active()->with('eventConfiguration')->first();
        }
    }

    #[Computed]
    public function stations(): Collection
    {
        if (! $this->hasPermission || ! $this->event?->eventConfiguration) {
            return collect();
        }

        return Station::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->with(['primaryRadio', 'operatingSessions'])
            ->get();
    }

    #[Computed]
    public function stationCount(): int
    {
        return $this->stations->count();
    }

    #[Computed]
    public function activeStations(): int
    {
        return $this->stations->filter(function ($station) {
            return $station->operatingSessions()
                ->where('start_time', '<=', appNow())
                ->where(function ($query) {
                    $query->whereNull('end_time')
                        ->orWhere('end_time', '>=', appNow());
                })
                ->exists();
        })->count();
    }

    public function render()
    {
        if (! $this->hasPermission) {
            return view('livewire.dashboard.widgets.equipment-status-restricted');
        }

        return view('livewire.dashboard.widgets.equipment-status');
    }
}
