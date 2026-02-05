<div>
    <x-mary-header title="Photo Gallery" subtitle="Field Day memories">
    </x-mary-header>

    @if($this->events->isEmpty())
        <x-mary-card>
            <div class="text-center py-12">
                <x-mary-icon name="o-photo" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No photos have been uploaded yet.</p>
            </div>
        </x-mary-card>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($this->events as $eventConfig)
                <x-mary-card class="hover:shadow-lg transition-shadow cursor-pointer" wire:click="$dispatch('navigate', { url: '{{ route('gallery.show', $eventConfig) }}' })">
                    <div class="aspect-video bg-base-300 rounded-lg overflow-hidden mb-4">
                        @if($eventConfig->images->first())
                            <img
                                src="{{ route('gallery.thumb', $eventConfig->images->first()) }}"
                                alt="Preview"
                                class="w-full h-full object-cover"
                            >
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <x-mary-icon name="o-photo" class="w-12 h-12 text-base-content/30" />
                            </div>
                        @endif
                    </div>
                    <h3 class="font-semibold text-lg">{{ $eventConfig->event->name }}</h3>
                    <p class="text-sm text-base-content/70">
                        {{ $eventConfig->event->start_time->format('F Y') }}
                    </p>
                    <p class="text-sm text-base-content/50 mt-1">
                        {{ $eventConfig->images_count }} {{ Str::plural('photo', $eventConfig->images_count) }}
                    </p>
                </x-mary-card>
            @endforeach
        </div>
    @endif
</div>
