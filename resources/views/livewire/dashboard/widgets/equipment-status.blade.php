<div>
    @if ($tvMode)
        {{-- TV Mode: Not shown in TV mode per config --}}
        <div class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border] text-center">
            <div class="text-2xl text-[--tv-text-muted]">
                Equipment Status not available in TV mode
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <x-mary-card title="Equipment Status" shadow separator>
            <x-slot:menu>
                <x-mary-icon name="phosphor-gear" class="w-5 h-5 text-gray-600" />
            </x-slot:menu>

            <div class="space-y-4">
                {{-- Summary Stats --}}
                <div class="grid grid-cols-2 gap-4">
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-3xl font-bold text-primary tabular-nums">
                            {{ $this->stationCount }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase mt-1">
                            Total Stations
                        </div>
                    </div>
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-3xl font-bold text-success tabular-nums">
                            {{ $this->activeStations }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase mt-1">
                            Active Now
                        </div>
                    </div>
                </div>

                {{-- Station List --}}
                @if ($this->stations->isEmpty())
                    <div class="text-center py-6 text-gray-500 dark:text-gray-400">
                        <x-mary-icon name="phosphor-gear" class="w-10 h-10 mx-auto mb-2 opacity-50" />
                        <p class="text-sm">No stations configured</p>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->stations as $station)
                            <div class="flex items-center justify-between p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full {{ $station->operatingSessions()->where('start_time', '<=', appNow())->where(function($q) { $q->whereNull('end_time')->orWhere('end_time', '>=', appNow()); })->exists() ? 'bg-success' : 'bg-gray-400' }}"></div>
                                    <span class="font-medium text-sm">{{ $station->name }}</span>
                                    @if ($station->is_gota)
                                        <span class="px-1.5 py-0.5 text-xs bg-warning/10 text-warning rounded">GOTA</span>
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $station->max_power_watts }}W
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-mary-card>
    @endif
</div>
