<?php

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

    #[Validate('required|image|mimes:jpeg,png,gif,webp|max:25600')]
    public $photo;

    #[Validate('nullable|string|max:500')]
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
            $this->addError('photo', 'Please upload a valid image file (JPEG, PNG, GIF, or WebP) under 25MB.');

            return;
        }

        $hash = $imageService->calculateHash($this->photo);

        // Check for existing image (including soft-deleted)
        $existingImage = Image::withTrashed()->where('file_hash', $hash)->first();

        if ($existingImage) {
            if ($existingImage->trashed()) {
                // Restore the soft-deleted image, update caption, and regenerate thumbnail
                $existingImage->restore();
                $existingImage->update(['caption' => $this->caption]);
                $imageService->regenerateThumbnail($existingImage->storage_path);

                $this->dispatch('notify', title: 'Success', description: 'Photo restored successfully!', type: 'success');
            } else {
                $this->addError('photo', 'This photo has already been uploaded.');

                return;
            }
        } else {
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

            $this->dispatch('notify', title: 'Success', description: 'Photo uploaded successfully!', type: 'success');
        }

        $this->redirectRoute('gallery.show', $this->eventConfiguration);
    }

    public function render(): View
    {
        return view('livewire.gallery.gallery-upload')
            ->layout('layouts.app');
    }
}
