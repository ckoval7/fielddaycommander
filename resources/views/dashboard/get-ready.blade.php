<x-layouts.app>
    <x-slot:title>Get Ready for {{ $event->name }}</x-slot:title>

    <div class="container mx-auto px-4 sm:px-6 py-8">
        <div class="max-w-2xl mx-auto">
            {{-- Event Header --}}
            <div class="text-center mb-8">
                <h1 class="text-3xl font-black text-base-content mb-2">{{ $event->name }}</h1>
                <p class="text-lg text-base-content/70">
                    {{ $event->start_time->format('l, F j, Y') }} at {{ $event->start_time->format('g:i A T') }}
                </p>
                @if($event->eventConfiguration)
                    <div class="flex items-center justify-center gap-4 mt-3 text-sm text-base-content/60">
                        @if($event->eventConfiguration->operatingClass)
                            <span>Class {{ $event->eventConfiguration->operatingClass->name }}</span>
                        @endif
                        @if($event->eventConfiguration->section)
                            <span>Section {{ $event->eventConfiguration->section->name }}</span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Countdown --}}
            <div class="text-center mb-8"
                 x-data="countdown(@js($event->start_time->toIso8601String()), @js(appNow()->toIso8601String()))"
                 x-init="init()">
                <div class="text-sm text-base-content/60 uppercase tracking-wide mb-1">Starts in</div>
                <div class="text-4xl font-mono font-black text-primary"
                     x-text="formattedTime">
                    --:--:--
                </div>
            </div>

            {{-- Setup Checklist --}}
            <div class="card bg-base-100 shadow-lg">
                <div class="card-body">
                    <h2 class="text-lg font-bold mb-4">Setup Checklist</h2>
                    <div class="space-y-3">
                        @foreach($checklist as $item)
                            <div class="flex items-center gap-3 p-3 rounded-lg {{ $item['done'] ? 'bg-success/5' : 'bg-base-200/50' }}">
                                <div class="flex-shrink-0">
                                    @if($item['done'])
                                        <x-icon name="o-check-circle" class="w-6 h-6 text-success" />
                                    @else
                                        <x-icon name="o-exclamation-circle" class="w-6 h-6 text-base-content/30" />
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <span class="{{ $item['done'] ? 'text-base-content/70' : 'font-medium' }}">
                                        {{ $item['label'] }}
                                    </span>
                                </div>
                                @if($item['route'])
                                    <a href="{{ $item['route'] }}" class="btn btn-ghost btn-sm">
                                        {{ $item['done'] ? 'Review' : 'Set Up' }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
