<div>
    <x-mary-header :title="$eventConfiguration->event->name" subtitle="Photo Gallery">
        <x-slot:actions>
            <x-mary-button label="Back to Gallery" icon="o-arrow-left" link="{{ route('gallery.index') }}" />
            @auth
                <x-mary-button label="Upload Photo" icon="o-arrow-up-tray" link="{{ route('gallery.upload', $eventConfiguration) }}" class="btn-primary" />
            @endauth
        </x-slot:actions>
    </x-mary-header>

    @if($this->images->isEmpty())
        <x-mary-card>
            <div class="text-center py-12">
                <x-mary-icon name="o-photo" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No photos have been uploaded for this event yet.</p>
                @auth
                    <x-mary-button label="Upload the first photo!" icon="o-arrow-up-tray" link="{{ route('gallery.upload', $eventConfiguration) }}" class="btn-primary mt-4" />
                @endauth
            </div>
        </x-mary-card>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($this->images as $image)
                <div class="group relative aspect-square bg-base-300 rounded-lg overflow-hidden cursor-pointer" wire:click="openLightbox({{ $image->id }})">
                    <img
                        src="{{ route('gallery.thumb', $image) }}"
                        alt="{{ $image->caption ?? 'Photo' }}"
                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                        loading="lazy"
                    >
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                        <div class="absolute bottom-0 left-0 right-0 p-3 text-white">
                            <p class="text-sm font-medium truncate">{{ $image->uploader->first_name }} {{ $image->uploader->last_name }}</p>
                            @if($image->caption)
                                <p class="text-xs opacity-80 truncate">{{ $image->caption }}</p>
                            @endif
                        </div>
                    </div>
                    @can('delete', $image)
                        <button
                            wire:click.stop="deleteImage({{ $image->id }})"
                            wire:confirm="Are you sure you want to delete this photo?"
                            class="absolute top-2 right-2 p-1.5 bg-error/80 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity hover:bg-error"
                        >
                            <x-mary-icon name="o-trash" class="w-4 h-4" />
                        </button>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    {{-- Lightbox Modal --}}
    <x-mary-modal wire:model="lightboxImageId" box-class="max-w-5xl">
        @if($lightboxImageId)
            @php $currentImage = $this->images->firstWhere('id', $lightboxImageId); @endphp
            @if($currentImage)
                <div class="space-y-4">
                    <img
                        src="{{ route('gallery.image', $currentImage) }}"
                        alt="{{ $currentImage->caption ?? 'Photo' }}"
                        class="w-full rounded-lg"
                    >
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="font-medium">{{ $currentImage->uploader->first_name }} {{ $currentImage->uploader->last_name }}</p>
                            <p class="text-sm text-base-content/70">{{ $currentImage->created_at->format('F j, Y g:i A') }}</p>
                            @if($currentImage->caption)
                                <p class="mt-2">{{ $currentImage->caption }}</p>
                            @endif
                        </div>
                        @can('delete', $currentImage)
                            <x-mary-button
                                label="Delete"
                                icon="o-trash"
                                class="btn-error btn-sm"
                                wire:click="deleteImage({{ $currentImage->id }})"
                                wire:confirm="Are you sure you want to delete this photo?"
                            />
                        @endcan
                    </div>
                </div>
            @endif
        @endif
    </x-mary-modal>
</div>
