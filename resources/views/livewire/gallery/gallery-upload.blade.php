<div>
    <x-header title="Upload Photo" :subtitle="$eventConfiguration->event->name">
        <x-slot:actions>
            <x-button label="Back to Gallery" icon="phosphor-arrow-left" link="{{ route('gallery.show', $eventConfiguration) }}" />
        </x-slot:actions>
    </x-header>

    <x-card>
        <form wire:submit="save" class="space-y-6">
            <div>
                <label for="photo-input" class="block text-sm font-medium mb-2">Photo</label>
                <div
                    x-data="{ isDragging: false }"
                    x-on:dragover.prevent="isDragging = true"
                    x-on:dragleave.prevent="isDragging = false"
                    x-on:drop.prevent="
                        isDragging = false;
                        if ($event.dataTransfer.files.length > 0) {
                            $refs.fileInput.files = $event.dataTransfer.files;
                            $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    "
                    :class="{ 'border-primary bg-primary/5': isDragging }"
                    class="border-2 border-dashed border-base-300 rounded-lg p-8 text-center transition-colors"
                >
                    <input
                        type="file"
                        wire:model="photo"
                        x-ref="fileInput"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        class="hidden"
                        id="photo-input"
                    >

                    @if($photo)
                        <div class="space-y-4">
                            @if(in_array($photo->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp']))
                                <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="max-h-64 mx-auto rounded-lg">
                            @endif
                            <p class="text-sm text-base-content/70">{{ $photo->getClientOriginalName() }}</p>
                            <x-button label="Choose Different Photo" icon="phosphor-arrow-clockwise" x-on:click="$refs.fileInput.click()" />
                        </div>
                    @else
                        <div class="space-y-4">
                            <x-icon name="phosphor-image" class="w-16 h-16 mx-auto text-base-content/30" />
                            <div>
                                <p class="text-base-content/70">Drag and drop a photo here, or</p>
                                <x-button label="Choose Photo" icon="phosphor-folder-open" x-on:click="$refs.fileInput.click()" class="mt-2" />
                            </div>
                            <p class="text-xs text-base-content/50">JPEG, PNG, GIF, or WebP up to 25MB</p>
                        </div>
                    @endif
                </div>
                @error('photo')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <x-input
                wire:model="caption"
                label="Caption (optional)"
                placeholder="Add a caption to your photo..."
                hint="Describe what's happening in the photo"
            />

            <div class="flex justify-end gap-3">
                <x-button label="Cancel" link="{{ route('gallery.show', $eventConfiguration) }}" />
                <x-button
                    label="Upload Photo"
                    icon="phosphor-upload-simple"
                    type="submit"
                    class="btn-primary"
                    wire:loading.attr="disabled"
                    spinner="save"
                />
            </div>
        </form>
    </x-card>
</div>
