<div>
    @if ($tvMode)
        {{-- TV Mode: Not shown in TV mode per config --}}
        <div class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border] text-center">
            <div class="text-2xl text-[--tv-text-muted]">
                Guestbook Stats not available in TV mode
            </div>
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <x-mary-card title="Guestbook Stats" shadow separator>
            <x-slot:menu>
                <x-mary-icon name="phosphor-book-open" class="w-5 h-5 text-success" />
            </x-slot:menu>

            <div class="space-y-4">
                {{-- Total Visitors --}}
                <div class="text-center p-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-lg">
                    <div class="text-4xl font-extrabold text-primary tabular-nums">
                        {{ number_format($this->totalVisitors) }}
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 uppercase tracking-wide mt-1">
                        Total Visitors
                    </div>
                </div>

                {{-- Breakdown --}}
                <div class="grid grid-cols-3 gap-3">
                    {{-- In Person --}}
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-success tabular-nums">
                            {{ number_format($this->inPersonVisitors) }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase mt-1">
                            In Person
                        </div>
                    </div>

                    {{-- Online --}}
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-info tabular-nums">
                            {{ number_format($this->onlineVisitors) }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase mt-1">
                            Online
                        </div>
                    </div>

                    {{-- VIP --}}
                    <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-warning tabular-nums">
                            {{ number_format($this->vipVisitors) }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase mt-1">
                            VIP
                        </div>
                    </div>
                </div>
            </div>
        </x-mary-card>
    @endif
</div>
