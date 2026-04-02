<div>
    <x-header title="Photo Gallery" subtitle="Field Day memories">
        @auth
            <x-slot:actions>
                @if($this->activeEventConfiguration)
                    {{-- Active event exists - direct link --}}
                    <x-button
                        label="Upload Photo"
                        icon="o-arrow-up-tray"
                        link="{{ route('gallery.upload', $this->activeEventConfiguration) }}"
                        class="btn-primary"
                    />
                @elseif($this->uploadableEvents->isNotEmpty())
                    {{-- No active event - show selector --}}
                    <x-button
                        label="Upload Photo"
                        icon="o-arrow-up-tray"
                        wire:click="$set('showEventSelector', true)"
                        class="btn-primary"
                    />
                @endif
            </x-slot:actions>
        @endauth
    </x-header>

    @if($this->events->isEmpty())
        <x-card class="shadow-md">
            <div class="text-center py-12">
                <x-icon name="o-photo" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No photos have been uploaded yet.</p>
                @auth
                    @if($this->activeEventConfiguration)
                        <x-button
                            label="Be the first to upload!"
                            icon="o-arrow-up-tray"
                            link="{{ route('gallery.upload', $this->activeEventConfiguration) }}"
                            class="btn-primary mt-4"
                        />
                    @elseif($this->uploadableEvents->isNotEmpty())
                        <x-button
                            label="Be the first to upload!"
                            icon="o-arrow-up-tray"
                            wire:click="$set('showEventSelector', true)"
                            class="btn-primary mt-4"
                        />
                    @endif
                @endauth
            </div>
        </x-card>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @foreach($this->events as $eventConfig)
                <div wire:key="event-{{ $eventConfig->id }}">
                    <x-card class="shadow-md hover:shadow-lg transition-shadow h-full">
                        <a href="{{ route('gallery.show', $eventConfig) }}" class="block cursor-pointer">
                            <div class="aspect-video bg-base-300 rounded-lg overflow-hidden mb-4">
                                @if($eventConfig->images->first())
                                    <img
                                        src="{{ route('gallery.thumb', $eventConfig->images->first()) }}"
                                        alt="Preview"
                                        class="w-full h-full object-cover"
                                    >
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <x-icon name="o-photo" class="w-12 h-12 text-base-content/30" />
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
                        </a>
                        @can('manage-images')
                            <div class="mt-3 pt-3 border-t border-base-200">
                                <form method="POST" action="{{ route('album-export.store', $eventConfig) }}">
                                    @csrf
                                    <x-button type="submit" label="Download" icon="o-arrow-down-tray" class="btn-sm btn-ghost w-full" />
                                </form>
                            </div>
                        @endcan
                    </x-card>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Event Selector Modal --}}
    <x-modal wire:model="showEventSelector" title="Select Event" box-class="max-w-md">
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
            <x-button label="Cancel" wire:click="$set('showEventSelector', false)" />
            @if($selectedEventId)
                <x-button
                    label="Continue"
                    icon="o-arrow-right"
                    class="btn-primary"
                    wire:click="uploadToEvent"
                />
            @endif
        </x-slot:actions>
    </x-modal>
</div>
