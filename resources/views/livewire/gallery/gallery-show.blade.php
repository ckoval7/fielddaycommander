{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-header :title="$eventConfiguration->event->name" subtitle="Photo Gallery">
        <x-slot:actions class="flex-wrap max-sm:w-full">
            <x-button label="All Albums" icon="phosphor-arrow-left" link="{{ route('gallery.index') }}" />
            @auth
                @can('manage-images')
                    @if($this->images->isNotEmpty())
                        <form method="POST" action="{{ route('album-export.store', $eventConfiguration) }}" class="inline">
                            @csrf
                            <x-button type="submit" label="Prepare Album ZIP" icon="phosphor-download-simple" class="btn-secondary" />
                        </form>
                    @endif
                @endcan
                @if($this->canUpload)
                    <x-button label="Upload Photo" icon="phosphor-upload-simple" link="{{ route('gallery.upload', $eventConfiguration) }}" class="btn-primary" />
                @endif
            @endauth
        </x-slot:actions>
    </x-header>

    @if(session('status'))
        <div class="alert alert-info mb-4">
            <x-icon name="phosphor-info" class="w-5 h-5" />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if($this->images->isEmpty())
        <x-card>
            <div class="text-center py-12">
                <x-icon name="phosphor-image" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No photos have been uploaded for this event yet.</p>
                @if($this->canUpload)
                    <x-button label="Upload the first photo!" icon="phosphor-upload-simple" link="{{ route('gallery.upload', $eventConfiguration) }}" class="btn-primary mt-4" />
                @endif
            </div>
        </x-card>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4">
            @foreach($this->images as $image)
                <div wire:key="image-{{ $image->id }}" class="group relative aspect-square bg-base-300 rounded-lg overflow-hidden cursor-pointer" wire:click="openLightbox({{ $image->id }})">
                    <img
                        src="{{ route('gallery.thumb', $image) }}"
                        alt="{{ $image->caption ?? 'Photo from '.$eventConfiguration->event->name }}"
                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                        loading="lazy"
                    >
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity">
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
                            wire:confirm="Delete this photo?"
                            type="button"
                            aria-label="Delete photo"
                            class="absolute top-2 right-2 flex items-center justify-center p-1.5 pointer-coarse:min-h-11 pointer-coarse:min-w-11 bg-red-700/80 text-white rounded-full opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 transition-opacity hover:bg-red-800 active:bg-red-900 touch-manipulation"
                        >
                            <x-icon name="phosphor-trash" class="w-4 h-4" />
                        </button>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    {{-- Lightbox Modal --}}
    <x-modal wire:model="lightboxImageId" box-class="max-w-5xl max-sm:w-full max-sm:max-w-none max-sm:px-3 max-sm:pb-3 pt-14">
        @if($lightboxImageId)
            @php $currentImage = $this->images->firstWhere('id', $lightboxImageId); @endphp
            @if($currentImage)
                <div class="space-y-4">
                    {{-- Tap to zoom around the tapped point; scroll to pan while zoomed. --}}
                    <div
                        wire:key="lightbox-{{ $currentImage->id }}"
                        x-data="{
                            zoomed: false,
                            toggle(event) {
                                const img = this.$refs.photo;
                                const rect = img.getBoundingClientRect();
                                const x = event ? (event.clientX - rect.left) / rect.width : 0.5;
                                const y = event ? (event.clientY - rect.top) / rect.height : 0.5;
                                this.zoomed = ! this.zoomed;
                                this.$nextTick(() => {
                                    const box = this.$refs.viewport;
                                    box.scrollLeft = this.zoomed ? x * img.offsetWidth - box.clientWidth / 2 : 0;
                                    box.scrollTop = this.zoomed ? y * img.offsetHeight - box.clientHeight / 2 : 0;
                                });
                            },
                        }"
                        class="space-y-2"
                    >
                        <div x-ref="viewport" class="max-h-[70dvh] overflow-auto overscroll-contain rounded-lg bg-base-200">
                            <button
                                type="button"
                                x-ref="photo"
                                class="block w-full"
                                :class="zoomed ? 'max-w-none !w-[250%] cursor-zoom-out' : 'cursor-zoom-in'"
                                :aria-label="zoomed ? 'Fit photo to screen' : 'Zoom into photo'"
                                @click="toggle($event)"
                            >
                                <img
                                    src="{{ route('gallery.image', $currentImage) }}"
                                    alt="{{ $currentImage->caption ?? 'Photo from '.$eventConfiguration->event->name }}"
                                    class="block w-full"
                                >
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn btn-sm btn-ghost"
                                @click="toggle()"
                                :aria-pressed="zoomed.toString()"
                            >
                                <x-icon name="phosphor-magnifying-glass-plus" class="w-4 h-4" x-show="! zoomed" />
                                <x-icon name="phosphor-magnifying-glass-minus" class="w-4 h-4" x-show="zoomed" x-cloak />
                                <span x-text="zoomed ? 'Fit to screen' : 'Zoom in'">Zoom in</span>
                            </button>
                            <a href="{{ route('gallery.image', $currentImage) }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">
                                <x-icon name="phosphor-arrows-out" class="w-4 h-4" />
                                Open Full-size Photo
                            </a>
                        </div>
                    </div>
                    <div class="flex justify-between items-start gap-3">
                        <div>
                            <p class="font-medium">{{ $currentImage->uploader->first_name }} {{ $currentImage->uploader->last_name }}</p>
                            <p class="text-sm text-base-content/70">{{ $currentImage->created_at->format('F j, Y g:i A') }}</p>
                            @if($currentImage->caption)
                                <p class="mt-2">{{ $currentImage->caption }}</p>
                            @endif
                        </div>
                        @can('delete', $currentImage)
                            <x-button
                                label="Delete Photo"
                                icon="phosphor-trash"
                                class="btn-error btn-sm"
                                wire:click="deleteImage({{ $currentImage->id }})"
                                wire:confirm="Delete this photo?"
                            />
                        @endcan
                    </div>
                </div>
            @endif
        @endif
    </x-modal>
</div>
