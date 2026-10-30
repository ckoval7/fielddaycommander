<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Gallery;

use App\Exceptions\ImageProcessingException;
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
        'photo.max' => 'Choose a photo up to 25 MB.',
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
        $problem = $this->photoProblem($imageService);

        if ($problem !== null) {
            $this->addError('photo', $problem);

            return;
        }

        try {
            $result = $imageService->store(
                $this->photo,
                'gallery/'.$this->eventConfiguration->id
            );
        } catch (ImageProcessingException) {
            $this->addError('photo', "This photo couldn't be processed. Save it as a JPEG and try again.");

            return;
        }

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

        session()->flash('toast', ['title' => 'Success', 'description' => 'Photo uploaded!', 'type' => 'success']);

        $this->redirectRoute('gallery.show', $this->eventConfiguration);
    }

    /**
     * Why the chosen photo can't be uploaded, or null if it can.
     */
    private function photoProblem(ImageService $imageService): ?string
    {
        if (! $imageService->isValidImage($this->photo)) {
            return 'Please upload a valid JPEG, PNG, GIF, or WebP photo up to 25 MB.';
        }

        if (! $imageService->hasAcceptableDimensions($this->photo)) {
            return 'This photo is too large or too narrow. Please resize or crop it and try again.';
        }

        // A deleted copy doesn't block the upload: it comes back as a new photo
        $alreadyInGallery = Image::query()
            ->where('event_configuration_id', $this->eventConfiguration->id)
            ->where('file_hash', $imageService->calculateHash($this->photo))
            ->exists();

        return $alreadyInGallery ? 'This photo is already in this album.' : null;
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-upload')
            ->layout('layouts.app', ['title' => 'Upload Photo']);
    }
}
