@props(['photoPath' => null, 'photoDescription' => null])

<x-modal wire:model="showPhotoModal" title="{{ $photoDescription ?? 'Equipment Detail' }}" class="backdrop-blur" box-class="max-w-4xl">
    @if($photoPath)
        <div class="flex justify-center items-center">
            <img
                src="{{ asset('storage/' . $photoPath) }}"
                alt="{{ $photoDescription ?? 'Equipment' }}"
                class="max-w-full max-h-[70vh] object-contain rounded"
            />
        </div>
    @endif

    <x-slot:actions>
        <x-button label="Close" @click="$wire.showPhotoModal = false" class="btn-ghost" />
    </x-slot:actions>
</x-modal>
