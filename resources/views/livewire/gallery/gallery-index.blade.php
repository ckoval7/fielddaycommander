<div>
    <x-mary-header title="Photo Gallery" subtitle="Field Day memories">
        @auth
            <x-slot:actions>
                @if($this->activeEventConfiguration)
                    {{-- Active event exists - direct link --}}
                    <x-mary-button
                        label="Upload Photo"
                        icon="o-arrow-up-tray"
                        link="{{ route('gallery.upload', $this->activeEventConfiguration) }}"
                        class="btn-primary"
                    />
                @elseif($this->uploadableEvents->isNotEmpty())
                    {{-- No active event - show selector --}}
                    <x-mary-button
                        label="Upload Photo"
                        icon="o-arrow-up-tray"
                        wire:click="$set('showEventSelector', true)"
                        class="btn-primary"
                    />
                @endif
            </x-slot:actions>
        @endauth
    </x-mary-header>

    @if($this->events->isEmpty())
        <x-mary-card>
            <div class="text-center py-12">
                <x-mary-icon name="o-photo" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No photos have been uploaded yet.</p>
                @auth
                    @if($this->activeEventConfiguration)
                        <x-mary-button
                            label="Be the first to upload!"
                            icon="o-arrow-up-tray"
                            link="{{ route('gallery.upload', $this->activeEventConfiguration) }}"
                            class="btn-primary mt-4"
                        />
                    @elseif($this->uploadableEvents->isNotEmpty())
                        <x-mary-button
                            label="Be the first to upload!"
                            icon="o-arrow-up-tray"
                            wire:click="$set('showEventSelector', true)"
                            class="btn-primary mt-4"
                        />
                    @endif
                @endauth
            </div>
        </x-mary-card>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @foreach($this->events as $eventConfig)
                <a href="{{ route('gallery.show', $eventConfig) }}" class="block">
                    <x-mary-card class="hover:shadow-lg transition-shadow cursor-pointer h-full">
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
                </a>
            @endforeach
        </div>
    @endif

    {{-- Event Selector Modal --}}
    <x-mary-modal wire:model="showEventSelector" title="Select Event" box-class="max-w-md">
        <p class="text-base-content/70 mb-4">Which event would you like to upload photos to?</p>

        <div class="space-y-2">
            @foreach($this->uploadableEvents as $eventConfig)
                <button
                    wire:click="$set('selectedEventId', {{ $eventConfig->id }})"
                    class="w-full p-3 text-left rounded-lg border transition-colors {{ $selectedEventId === $eventConfig->id ? 'border-primary bg-primary/10' : 'border-base-300 hover:border-primary/50' }}"
                >
                    <div class="font-medium">{{ $eventConfig->event->name }}</div>
                    <div class="text-sm text-base-content/70">{{ $eventConfig->event->start_time->format('F j, Y') }}</div>
                </button>
            @endforeach
        </div>

        <x-slot:actions>
            <x-mary-button label="Cancel" wire:click="$set('showEventSelector', false)" />
            @if($selectedEventId)
                <x-mary-button
                    label="Continue"
                    icon="o-arrow-right"
                    class="btn-primary"
                    wire:click="uploadToEvent"
                />
            @endif
        </x-slot:actions>
    </x-mary-modal>
</div>
