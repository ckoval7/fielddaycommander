<div>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border]">
            <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider mb-6">
                Progress Toward Goals
            </div>

            <div class="space-y-8">
                {{-- QSO Goal --}}
                <div>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-2xl font-semibold text-[--tv-text]">QSO Goal</span>
                        <span class="text-3xl font-bold text-[--tv-accent-hot] tabular-nums">
                            {{ number_format($this->currentQsos) }} / {{ number_format($qsoGoal) }}
                        </span>
                    </div>
                    <div class="w-full bg-[--tv-border] rounded-full h-6">
                        <div
                            class="h-6 rounded-full transition-all duration-1000"
                            style="width: {{ $this->qsoProgress }}%; background-color: var(--tv-status-{{ $this->qsoStatus === 'complete' ? 'excellent' : ($this->qsoStatus === 'excellent' ? 'excellent' : ($this->qsoStatus === 'good' ? 'good' : 'warning')) }});"
                        ></div>
                    </div>
                    <div class="text-right mt-2 text-2xl text-[--tv-text-muted]">
                        {{ $this->qsoProgress }}% Complete
                    </div>
                </div>

                {{-- Score Goal --}}
                <div>
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-2xl font-semibold text-[--tv-text]">Score Goal</span>
                        <span class="text-3xl font-bold text-[--tv-accent-gold] tabular-nums">
                            {{ number_format($this->currentScore) }} / {{ number_format($scoreGoal) }}
                        </span>
                    </div>
                    <div class="w-full bg-[--tv-border] rounded-full h-6">
                        <div
                            class="h-6 rounded-full transition-all duration-1000"
                            style="width: {{ $this->scoreProgress }}%; background-color: var(--tv-status-{{ $this->scoreStatus === 'complete' ? 'excellent' : ($this->scoreStatus === 'excellent' ? 'excellent' : ($this->scoreStatus === 'good' ? 'good' : 'warning')) }});"
                        ></div>
                    </div>
                    <div class="text-right mt-2 text-2xl text-[--tv-text-muted]">
                        {{ $this->scoreProgress }}% Complete
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <x-mary-card title="Progress Toward Goals" shadow separator>
            <x-slot:menu>
                <x-mary-icon name="phosphor-chart-pie" class="w-5 h-5 text-accent" />
            </x-slot:menu>

            <div class="space-y-6">
                {{-- QSO Goal --}}
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">QSO Goal</span>
                        <span class="text-lg font-bold text-primary tabular-nums">
                            {{ number_format($this->currentQsos) }} / {{ number_format($qsoGoal) }}
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                        <div
                            @class([
                                'h-3 rounded-full transition-all duration-1000',
                                'bg-success' => $this->qsoStatus === 'complete' || $this->qsoStatus === 'excellent',
                                'bg-info' => $this->qsoStatus === 'good',
                                'bg-warning' => $this->qsoStatus === 'fair',
                                'bg-error' => $this->qsoStatus === 'behind',
                            ])
                            style="width: {{ $this->qsoProgress }}%"
                        ></div>
                    </div>
                    <div class="flex justify-between mt-1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $this->qsoProgress }}%</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ number_format($qsoGoal - $this->currentQsos) }} remaining
                        </span>
                    </div>
                </div>

                {{-- Score Goal --}}
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Score Goal</span>
                        <span class="text-lg font-bold text-warning tabular-nums">
                            {{ number_format($this->currentScore) }} / {{ number_format($scoreGoal) }}
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                        <div
                            @class([
                                'h-3 rounded-full transition-all duration-1000',
                                'bg-success' => $this->scoreStatus === 'complete' || $this->scoreStatus === 'excellent',
                                'bg-info' => $this->scoreStatus === 'good',
                                'bg-warning' => $this->scoreStatus === 'fair',
                                'bg-error' => $this->scoreStatus === 'behind',
                            ])
                            style="width: {{ $this->scoreProgress }}%"
                        ></div>
                    </div>
                    <div class="flex justify-between mt-1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $this->scoreProgress }}%</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ number_format($scoreGoal - $this->currentScore) }} remaining
                        </span>
                    </div>
                </div>
            </div>
        </x-mary-card>
    @endif
</div>
