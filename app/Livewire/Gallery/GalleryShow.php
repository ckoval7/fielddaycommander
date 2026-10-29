<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Gallery;

use App\Models\EventConfiguration;
use App\Models\Image;
use App\Support\StationMode;
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

    /**
     * Whether the current user may upload photos. Station-mode operators may not.
     */
    #[Computed]
    public function canUpload(): bool
    {
        return auth()->check() && ! StationMode::isActive();
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
            ->layout('layouts.app', ['title' => 'Photo Gallery']);
    }
}
