<?php

namespace App\Livewire\Gallery;

use App\Models\Event;
use App\Models\EventConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GalleryIndex extends Component
{
    public bool $showEventSelector = false;

    public ?int $selectedEventId = null;

    #[Computed]
    public function events(): Collection
    {
        return EventConfiguration::query()
            ->whereHas('images')
            ->withCount('images')
            ->with(['event', 'images' => fn ($q) => $q->latest()->limit(1)])
            ->get()
            ->sortByDesc(fn ($ec) => $ec->event->start_time);
    }

    #[Computed]
    public function activeEventConfiguration(): ?EventConfiguration
    {
        $activeEvent = Event::active()->with('eventConfiguration')->first();

        return $activeEvent?->eventConfiguration;
    }

    #[Computed]
    public function uploadableEvents(): Collection
    {
        return EventConfiguration::query()
            ->with('event')
            ->whereHas('event', fn ($q) => $q->whereNull('deleted_at'))
            ->get()
            ->sortByDesc(fn ($ec) => $ec->event->start_time);
    }

    public function uploadToEvent(): void
    {
        if ($this->selectedEventId) {
            $this->redirect(route('gallery.upload', $this->selectedEventId));
        }
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-index')
            ->layout('layouts.app');
    }
}
