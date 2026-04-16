<div>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border]">
            <div class="text-center">
                <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider mb-4">
                    Time Remaining
                </div>
                <div class="text-7xl font-extrabold text-[--tv-text] tabular-nums font-mono tracking-wider">
                    {{ $this->timeRemaining['formatted'] }}
                </div>
                <div class="mt-6 text-3xl text-[--tv-text-muted]">
                    {{ $this->eventStatus }}
                </div>
                @if ($this->timeRemaining['percentage'] > 0)
                    <div class="mt-8">
                        <div class="w-full bg-[--tv-border] rounded-full h-4">
                            <div
                                class="h-4 rounded-full transition-all duration-1000"
                                style="width: {{ $this->timeRemaining['percentage'] }}%; background-color: var(--tv-primary);"
                            ></div>
                        </div>
                        <div class="mt-2 text-2xl text-[--tv-text-muted]">
                            {{ $this->timeRemaining['percentage'] }}% Complete
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <x-mary-card title="Time Remaining" shadow separator>
            <x-slot:menu>
                <x-mary-icon name="phosphor-clock" class="w-5 h-5 text-info" />
            </x-slot:menu>

            <div class="space-y-4">
                {{-- Countdown Timer --}}
                <div class="text-center">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        {{ $this->eventStatus }}
                    </div>
                    <div class="text-5xl font-extrabold text-info mt-2 tabular-nums font-mono">
                        {{ $this->timeRemaining['formatted'] }}
                    </div>
                </div>

                {{-- Progress Bar --}}
                @if ($this->timeRemaining['percentage'] > 0 && $this->timeRemaining['percentage'] < 100)
                    <div class="pt-2">
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div
                                class="bg-info h-2 rounded-full transition-all duration-1000"
                                style="width: {{ $this->timeRemaining['percentage'] }}%"
                            ></div>
                        </div>
                        <div class="text-center mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ $this->timeRemaining['percentage'] }}% of event completed
                        </div>
                    </div>
                @endif

                {{-- Time Breakdown --}}
                @if ($this->timeRemaining['total_seconds'] > 0)
                    <div class="grid grid-cols-3 gap-2 pt-2">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-primary tabular-nums">
                                {{ $this->timeRemaining['hours'] }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">Hours</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-primary tabular-nums">
                                {{ sprintf('%02d', $this->timeRemaining['minutes']) }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">Minutes</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-primary tabular-nums">
                                {{ sprintf('%02d', $this->timeRemaining['seconds']) }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">Seconds</div>
                        </div>
                    </div>
                @endif
            </div>
        </x-mary-card>
    @endif
</div>
