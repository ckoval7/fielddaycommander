<div
    x-data="{ flash: false }"
    @qso-logged.window="flash = true; setTimeout(() => flash = false, 800)"
>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div
            class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border] transition-all duration-300"
            :class="flash ? 'ring-8 ring-[--tv-status-excellent] ring-opacity-50 scale-[1.02]' : ''"
        >
            <div class="text-center">
                <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider mb-4">
                    QSO Count
                </div>
                <div
                    class="text-9xl font-extrabold tabular-nums transition-colors duration-500"
                    :class="flash ? 'text-[--tv-status-excellent]' : 'text-[--tv-accent-hot]'"
                >
                    {{ number_format($this->qsoCount) }}
                </div>
                <div class="mt-8 text-5xl font-bold text-[--tv-text]">
                    <span class="text-[--tv-text-muted]">Rate:</span>
                    <span class="text-[--tv-primary-bright] tabular-nums">{{ number_format($this->qsoRate, 1) }}</span>
                    <span class="text-[--tv-text-muted] text-4xl">/hour</span>
                </div>
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <div
            class="transition-all duration-300"
            :class="flash ? 'ring-4 ring-success ring-opacity-50 rounded-lg' : ''"
        >
            <x-mary-card title="QSO Count & Rate" shadow separator>
                <x-slot:menu>
                    <x-mary-icon name="phosphor-radio" class="w-5 h-5 text-primary" />
                </x-slot:menu>

                <div class="space-y-6">
                    {{-- QSO Count --}}
                    <div class="text-center">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            Total Contacts
                        </div>
                        <div
                            class="text-6xl font-extrabold mt-2 tabular-nums transition-all duration-500"
                            :class="flash ? 'text-success scale-110' : 'text-primary scale-100'"
                        >
                            {{ number_format($this->qsoCount) }}
                        </div>
                    </div>

                    {{-- Divider --}}
                    <div class="border-t border-gray-200 dark:border-gray-700"></div>

                    {{-- QSO Rate --}}
                    <div class="text-center">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            Current Rate
                        </div>
                        <div class="text-3xl font-bold text-accent mt-2 tabular-nums">
                            {{ number_format($this->qsoRate, 1) }}
                            <span class="text-lg text-gray-500 dark:text-gray-400">QSOs/hour</span>
                        </div>
                    </div>
                </div>
            </x-mary-card>
        </div>
    @endif
</div>
