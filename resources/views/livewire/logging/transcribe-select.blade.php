<div>
    {{-- Breadcrumb --}}
    @php
        $breadcrumbs = [
            ['label' => 'Home', 'link' => route('dashboard'), 'icon' => 'phosphor-house-fill'],
            ['label' => 'Transcribe Log'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" class="mb-6" />

    @if(! $this->event)
        {{-- No Event State --}}
        <x-card>
            <div class="text-center py-12">
                <x-icon name="phosphor-file-text" class="w-14 h-14 mx-auto text-base-content/25" />
                <h3 class="mt-5 text-xl font-semibold">No event available for transcription</h3>
                <p class="mt-2 text-base-content/60 max-w-md mx-auto">
                    Transcription is only available during an active event or within the grace period following an event.
                </p>
                <div class="mt-6">
                    <x-button label="Return Home" link="{{ route('dashboard') }}" icon="phosphor-house" class="btn-ghost" />
                </div>
            </div>
        </x-card>
    @else
        {{-- Page Header --}}
        <div class="mb-8">
            <div class="flex items-start gap-4">
                <div class="rounded-xl bg-primary/10 p-3 hidden sm:flex">
                    <x-icon name="phosphor-file-text" class="w-7 h-7 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold">Transcribe Paper Log</h1>
                    <p class="mt-1 text-base-content/65">
                        Select the station you operated during the event to begin entering contacts from your paper log.
                    </p>
                </div>
            </div>
        </div>

        {{-- Station Grid --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @forelse($this->stations as $station)
                <a
                    href="{{ route('logging.transcribe.session', $station) }}"
                    class="group block rounded-2xl border-2 border-base-300 bg-base-100 p-5 transition-all duration-150
                           hover:border-primary/50 hover:shadow-lg hover:shadow-primary/10 hover:-translate-y-0.5"
                >
                    {{-- Card Header --}}
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold truncate group-hover:text-primary transition-colors">
                                {{ $station->name }}
                            </h3>
                        </div>
                        @if($station->is_gota)
                            <span class="badge badge-info badge-sm shrink-0 mt-0.5">GOTA</span>
                        @endif
                    </div>

                    {{-- Divider --}}
                    <div class="border-t border-base-200 my-3"></div>

                    {{-- Radio Equipment --}}
                    <div class="flex items-center gap-2 text-sm">
                        <x-icon name="phosphor-radio" class="w-4 h-4 text-base-content/40 shrink-0" />
                        @if($station->primaryRadio)
                            <span class="font-medium text-base-content">
                                {{ $station->primaryRadio->make }}
                                {{ $station->primaryRadio->model }}
                            </span>
                        @else
                            <span class="text-base-content/40 italic">No radio configured</span>
                        @endif
                    </div>

                    {{-- Max Power --}}
                    @if($station->max_power_watts)
                        <div class="flex items-center gap-2 text-sm mt-2">
                            <x-icon name="phosphor-lightning" class="w-4 h-4 text-base-content/40 shrink-0" />
                            <span class="text-base-content/70">{{ $station->max_power_watts }} W max</span>
                        </div>
                    @endif

                    {{-- CTA --}}
                    <div class="mt-4 flex items-center justify-between">
                        <span class="text-xs text-base-content/40 uppercase tracking-wide font-medium">
                            {{ $station->is_gota ? 'GOTA Station' : 'Operating Station' }}
                        </span>
                        <span class="flex items-center gap-1 text-xs font-semibold text-primary opacity-0 group-hover:opacity-100 transition-opacity">
                            Select
                            <x-icon name="phosphor-arrow-right" class="w-3.5 h-3.5" />
                        </span>
                    </div>
                </a>
            @empty
                <div class="col-span-full">
                    <x-card>
                        <div class="text-center py-10">
                            <x-icon name="phosphor-hard-drives" class="w-12 h-12 mx-auto text-base-content/25" />
                            <h3 class="mt-4 text-lg font-semibold">No Stations Configured</h3>
                            <p class="mt-2 text-base-content/60">
                                No stations have been set up for this event yet. Contact your event administrator.
                            </p>
                        </div>
                    </x-card>
                </div>
            @endforelse
        </div>
    @endif
</div>
