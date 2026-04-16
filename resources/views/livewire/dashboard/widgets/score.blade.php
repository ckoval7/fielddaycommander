<div
    x-data="{ flash: false }"
    @qso-logged.window="flash = true; setTimeout(() => flash = false, 800)"
>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div
            class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border] transition-all duration-300"
            :class="flash ? 'ring-8 ring-[--tv-accent-gold] ring-opacity-50' : ''"
        >
            <div class="text-center">
                <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider mb-4">
                    Current Score
                </div>
                <div
                    class="text-9xl font-extrabold tabular-nums transition-all duration-500"
                    :class="flash ? 'text-[--tv-status-excellent] scale-105' : 'text-[--tv-accent-gold]'"
                >
                    {{ number_format($this->finalScore) }}
                </div>
                <div class="mt-8 grid grid-cols-2 gap-6">
                    <div class="text-center">
                        <div class="text-2xl text-[--tv-text-muted]">QSO Points</div>
                        <div class="text-4xl font-bold text-[--tv-primary] mt-2 tabular-nums">
                            {{ number_format($this->qsoScore) }}
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl text-[--tv-text-muted]">Bonus Points</div>
                        <div class="text-4xl font-bold text-[--tv-status-good] mt-2 tabular-nums">
                            {{ number_format($this->bonusScore) }}
                        </div>
                    </div>
                </div>
                <div class="mt-6 text-3xl text-[--tv-text-muted]">
                    Power Multiplier: <span class="text-[--tv-accent-hot] font-bold">{{ $this->powerMultiplier }}×</span>
                </div>
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <div
            class="transition-all duration-300"
            :class="flash ? 'ring-4 ring-warning ring-opacity-50 rounded-lg' : ''"
        >
            <x-mary-card title="Current Score" shadow separator>
                <x-slot:menu>
                    <x-mary-icon name="phosphor-star" class="w-5 h-5 text-warning" />
                </x-slot:menu>

                <div class="space-y-6">
                    {{-- Final Score --}}
                    <div class="text-center">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            Total Score
                        </div>
                        <div
                            class="text-6xl font-extrabold mt-2 tabular-nums transition-all duration-500"
                            :class="flash ? 'text-success scale-105' : 'text-warning'"
                        >
                            {{ number_format($this->finalScore) }}
                        </div>
                    </div>

                {{-- Divider --}}
                <div class="border-t border-gray-200 dark:border-gray-700"></div>

                {{-- Score Breakdown --}}
                <div class="grid grid-cols-2 gap-4">
                    {{-- QSO Score --}}
                    <div class="text-center">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                            QSO Points
                        </div>
                        <div class="text-2xl font-bold text-primary mt-1 tabular-nums">
                            {{ number_format($this->qsoScore) }}
                        </div>
                    </div>

                    {{-- Bonus Score --}}
                    <div class="text-center">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                            Bonus Points
                        </div>
                        <div class="text-2xl font-bold text-success mt-1 tabular-nums">
                            {{ number_format($this->bonusScore) }}
                        </div>
                    </div>
                </div>

                {{-- Power Multiplier --}}
                <div class="text-center pt-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Power Multiplier:</span>
                    <span class="text-lg font-bold text-accent ml-2">{{ $this->powerMultiplier }}×</span>
                </div>
            </div>
        </x-mary-card>
    @endif
</div>
