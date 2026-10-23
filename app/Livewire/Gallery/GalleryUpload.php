<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Gallery;

use App\Models\EventConfiguration;
use App\Models\Image;
use App\Services\ImageService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class GalleryUpload extends Component
{
    use WithFileUploads;

    public EventConfiguration $eventConfiguration;

    #[Validate('required|image|mimes:jpeg,png,gif,webp|max:25600', message: [
        'photo.required' => 'Choose a photo to upload.',
        'photo.image' => 'Upload a valid JPEG, PNG, GIF, or WebP photo.',
        'photo.mimes' => 'Upload a JPEG, PNG, GIF, or WebP photo.',
        'photo.max' => 'Choose a photo up to 12 MB.',
    ])]
    public $photo;

    #[Validate('nullable|string|max:500', message: [
        'caption.string' => 'Enter a text caption.',
        'caption.max' => 'Keep the caption to 500 characters or fewer.',
    ])]
    public ?string $caption = null;

    public function mount(EventConfiguration $eventConfiguration): void
    {
        abort_unless(auth()->check(), 403);

        $this->eventConfiguration = $eventConfiguration;
    }

    public function save(): void
    {
        $this->validate();

        $imageService = app(ImageService::class);

        if (! $imageService->isValidImage($this->photo)) {
            $this->addError('photo', 'Upload a valid JPEG, PNG, GIF, or WebP photo up to 12 MB.');

            return;
        }

        if (! $imageService->hasAcceptableDimensions($this->photo)) {
            $this->addError('photo', 'Choose a photo up to 50 megapixels, with the longer side no more than 20 times the shorter side.');

            return;
        }

        $hash = $imageService->calculateHash($this->photo);

        // A deleted copy doesn't block the upload: it comes back as a new photo
        $alreadyInGallery = Image::query()
            ->where('event_configuration_id', $this->eventConfiguration->id)
            ->where('file_hash', $hash)
            ->exists();

        if ($alreadyInGallery) {
            $this->addError('photo', 'This photo is already in this album.');

            return;
        }

        $result = $imageService->store(
            $this->photo,
            'gallery/'.$this->eventConfiguration->id
        );

        Image::create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'uploaded_by_user_id' => auth()->id(),
            'filename' => $this->photo->getClientOriginalName(),
            'storage_path' => $result->path,
            'mime_type' => $result->mimeType,
            'file_size_bytes' => $result->size,
            'file_hash' => $result->hash,
            'caption' => $this->caption,
        ]);

        session()->flash('toast', ['title' => 'Success', 'description' => 'Photo uploaded.', 'type' => 'success']);

        $this->redirectRoute('gallery.show', $this->eventConfiguration);
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-upload')
            ->layout('layouts.app');
    }
}
