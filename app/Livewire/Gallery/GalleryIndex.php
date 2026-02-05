<?php

namespace App\Livewire\Gallery;

use App\Models\EventConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GalleryIndex extends Component
{
    #[Computed]
    public function events(): Collection
    {
        return EventConfiguration::query()
            ->whereHas('images')
            ->withCount('images')
            ->with(['event'])
            ->get()
            ->sortByDesc(fn ($ec) => $ec->event->start_time);
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-index')
            ->layout('layouts.app');
    }
}
