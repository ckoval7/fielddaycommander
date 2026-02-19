<?php

namespace App\Livewire\Logging;

use App\Models\Event;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TranscribeSelect extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('log-contacts');
    }

    #[Computed]
    public function event(): ?Event
    {
        $service = app(EventContextService::class);
        $contextEvent = $service->getContextEvent();

        if (! $contextEvent) {
            return null;
        }

        $status = $service->getGracePeriodStatus($contextEvent);

        if (in_array($status, ['active', 'grace'])) {
            return $contextEvent;
        }

        return null;
    }

    #[Computed]
    public function stations()
    {
        $event = $this->event;
        if (! $event?->eventConfiguration) {
            return collect();
        }

        return \App\Models\Station::where('event_configuration_id', $event->eventConfiguration->id)
            ->with('primaryRadio')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.logging.transcribe-select')
            ->layout('layouts.app');
    }
}
