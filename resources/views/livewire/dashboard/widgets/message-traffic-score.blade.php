<div>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border]">
            <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider mb-6 text-center">
                Message Traffic
            </div>

            <div class="space-y-4">
                {{-- SM/SEC Message --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if ($this->bonusSummary['sm_message'])
                            <x-mary-icon name="o-check-circle" class="w-8 h-8 text-[--tv-status-excellent]" />
                        @else
                            <x-mary-icon name="o-x-circle" class="w-8 h-8 text-[--tv-status-poor]" />
                        @endif
                        <span class="text-2xl text-[--tv-text-muted]">SM/SEC Message</span>
                    </div>
                    <span class="text-2xl font-bold text-[--tv-primary] tabular-nums">
                        {{ $this->bonusSummary['sm_points'] }} pts
                    </span>
                </div>

                {{-- Traffic Messages --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl font-bold text-[--tv-accent-gold] tabular-nums w-8 text-center">
                            {{ $this->bonusSummary['traffic_count'] }}
                        </span>
                        <span class="text-2xl text-[--tv-text-muted]">Traffic ({{ $this->bonusSummary['traffic_count'] }}/10)</span>
                    </div>
                    <span class="text-2xl font-bold text-[--tv-primary] tabular-nums">
                        {{ $this->bonusSummary['traffic_points'] }} pts
                    </span>
                </div>

                {{-- W1AW Bulletin --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if ($this->bonusSummary['w1aw_bulletin'])
                            <x-mary-icon name="o-check-circle" class="w-8 h-8 text-[--tv-status-excellent]" />
                        @else
                            <x-mary-icon name="o-x-circle" class="w-8 h-8 text-[--tv-status-poor]" />
                        @endif
                        <span class="text-2xl text-[--tv-text-muted]">W1AW Bulletin</span>
                    </div>
                    <span class="text-2xl font-bold text-[--tv-primary] tabular-nums">
                        {{ $this->bonusSummary['w1aw_points'] }} pts
                    </span>
                </div>

                {{-- Divider --}}
                <div class="border-t border-[--tv-border] pt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-2xl font-bold text-[--tv-text-muted] uppercase tracking-wide">Total</span>
                        <span class="text-3xl font-extrabold text-[--tv-accent-gold] tabular-nums">
                            {{ $this->bonusSummary['total'] }} pts
                        </span>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <x-mary-card title="Message Traffic" shadow separator>
            <x-slot:menu>
                <x-mary-icon name="o-envelope" class="w-5 h-5 text-info" />
            </x-slot:menu>

            <div class="space-y-3">
                {{-- SM/SEC Message --}}
                <div class="flex items-center justify-between py-1">
                    <div class="flex items-center gap-2">
                        @if ($this->bonusSummary['sm_message'])
                            <x-mary-icon name="o-check-circle" class="w-5 h-5 text-success" />
                        @else
                            <x-mary-icon name="o-x-circle" class="w-5 h-5 text-error" />
                        @endif
                        <span class="text-sm text-gray-700 dark:text-gray-300">SM/SEC Message</span>
                    </div>
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ $this->bonusSummary['sm_points'] }} pts
                    </span>
                </div>

                {{-- Traffic Messages --}}
                <div class="flex items-center justify-between py-1">
                    <div class="flex items-center gap-2">
                        <span class="w-5 text-center text-sm font-bold text-info tabular-nums">
                            {{ $this->bonusSummary['traffic_count'] }}
                        </span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Traffic ({{ $this->bonusSummary['traffic_count'] }}/10)</span>
                    </div>
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ $this->bonusSummary['traffic_points'] }} pts
                    </span>
                </div>

                {{-- W1AW Bulletin --}}
                <div class="flex items-center justify-between py-1">
                    <div class="flex items-center gap-2">
                        @if ($this->bonusSummary['w1aw_bulletin'])
                            <x-mary-icon name="o-check-circle" class="w-5 h-5 text-success" />
                        @else
                            <x-mary-icon name="o-x-circle" class="w-5 h-5 text-error" />
                        @endif
                        <span class="text-sm text-gray-700 dark:text-gray-300">W1AW Bulletin</span>
                    </div>
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ $this->bonusSummary['w1aw_points'] }} pts
                    </span>
                </div>

                {{-- Divider --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Total</span>
                        <span class="text-lg font-extrabold tabular-nums text-primary">
                            {{ $this->bonusSummary['total'] }} pts
                        </span>
                    </div>
                </div>

                {{-- Link to message traffic page --}}
                @if ($event)
                    <div class="pt-1">
                        <a
                            href="{{ route('events.messages.index', $event) }}"
                            class="text-xs text-info hover:underline"
                        >
                            View message traffic &rarr;
                        </a>
                    </div>
                @endif
            </div>
        </x-mary-card>
    @endif
</div>
