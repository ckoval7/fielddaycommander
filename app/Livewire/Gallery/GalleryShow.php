<?php

namespace App\Livewire\Gallery;

use App\Models\EventConfiguration;
use App\Models\Image;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GalleryShow extends Component
{
    use AuthorizesRequests;

    public EventConfiguration $eventConfiguration;

    public ?int $lightboxImageId = null;

    public function mount(EventConfiguration $eventConfiguration): void
    {
        $this->eventConfiguration = $eventConfiguration;
    }

    #[Computed]
    public function images(): Collection
    {
        return $this->eventConfiguration
            ->images()
            ->with('uploader')
            ->latest()
            ->get();
    }

    public function openLightbox(int $imageId): void
    {
        $this->lightboxImageId = $imageId;
    }

    public function closeLightbox(): void
    {
        $this->lightboxImageId = null;
    }

    public function deleteImage(int $imageId): void
    {
        $image = Image::findOrFail($imageId);

        $this->authorize('delete', $image);

        // Soft delete only - keep file for potential restore
        $image->delete();

        $this->closeLightbox();

        $this->dispatch('notify', title: 'Success', description: 'Photo deleted.', type: 'success');
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-show')
            ->layout('layouts.app');
    }
}
