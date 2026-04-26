{{--
StatCard Widget View

Displays a single metric in a card with icon, value, and label.
Supports normal and TV size variants with count-up animation.

Props from component:
- $data: Array with 'value', 'label', 'icon', 'color'
- $size: 'normal' or 'tv'
--}}

<div
    class="h-full"
    wire:poll.visible.10s
    x-data="statCardWidget"
>
    @if ($size === 'tv')
        {{-- TV Mode: Left-aligned stat display for kiosk/TV dashboards --}}
        <x-card class="h-full flex flex-col p-6 sm:p-8" shadow>
            <div class="flex-1 flex flex-col">
                {{-- Top row: icon badge left, trend right --}}
                <div class="flex items-start justify-between mb-auto">
                    <div class="flex items-center justify-center w-14 h-14 rounded-full {{ str_replace('text-', 'bg-', $data['color']) }}/10">
                        <x-icon
                            :name="$data['icon']"
                            class="w-8 h-8 {{ $data['color'] }} transition-transform duration-300"
                            ::class="{ 'scale-110': isAnimating }"
                        />
                    </div>
                    @if($showTrend && isset($data['trend']) && $data['trend'] !== 'stable')
                        <div class="flex items-center gap-2 text-lg font-medium px-3 py-1 rounded-full {{ $data['trend'] === 'up' ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}">
                            @if($data['trend'] === 'up')
                                <x-icon name="phosphor-trend-up" class="w-6 h-6" />
                                <span>+{{ number_format($data['change_amount']) }}</span>
                            @else
                                <x-icon name="phosphor-trend-down" class="w-6 h-6" />
                                <span>{{ number_format($data['change_amount']) }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Big number --}}
                <div
                    class="text-5xl sm:text-6xl lg:text-7xl font-black tabular-nums {{ $data['color'] }} transition-all duration-300 mt-4"
                    ::class="{ 'scale-105': isAnimating }"
                    x-text="displayValue"
                ></div>

                {{-- Label --}}
                <div class="text-xl sm:text-2xl text-base-content/70 mt-2">
                    {{ $data['label'] }}
                </div>
            </div>
            <div class="text-xs text-base-content/40 text-right mt-auto pt-2">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
        </x-card>
    @else
        {{-- Normal Mode: Left-aligned stat display with icon badge and trend --}}
        <x-card class="h-full flex flex-col" shadow>
            <div class="flex-1 flex flex-col py-2">
                {{-- Top row: icon badge left, trend right --}}
                <div class="flex items-start justify-between mb-auto">
                    <div class="flex items-center justify-center w-9 h-9 rounded-full {{ str_replace('text-', 'bg-', $data['color']) }}/10">
                        <x-icon
                            :name="$data['icon']"
                            class="w-5 h-5 {{ $data['color'] }} transition-transform duration-200"
                            ::class="{ 'scale-110': isAnimating }"
                        />
                    </div>
                    @if($showTrend && isset($data['trend']) && $data['trend'] !== 'stable')
                        <div class="flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full {{ $data['trend'] === 'up' ? 'bg-success/10 text-success' : 'bg-error/10 text-error' }}">
                            @if($data['trend'] === 'up')
                                <x-icon name="phosphor-trend-up" class="w-3.5 h-3.5" />
                                <span>+{{ number_format($data['change_amount']) }}</span>
                            @else
                                <x-icon name="phosphor-trend-down" class="w-3.5 h-3.5" />
                                <span>{{ number_format($data['change_amount']) }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Big number --}}
                <div
                    class="text-6xl font-black tabular-nums {{ $data['color'] }} transition-all duration-200 mt-2"
                    ::class="{ 'scale-105': isAnimating }"
                    x-text="displayValue"
                ></div>

                {{-- Label --}}
                <div class="text-sm text-base-content/60 mt-1">
                    {{ $data['label'] }}
                </div>
            </div>
            <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
        </x-card>
    @endif
</div>

@script
<script>
    Alpine.data('statCardWidget', () => ({
        displayValue: 0,
        targetValue: 0,
        isAnimating: false,

        init() {
            this.displayValue = parseFloat(String(this.$wire.statValue).replace(/,/g, '')) || 0;
            this.targetValue = this.displayValue;

            this.$wire.$watch('statValue', (newValue) => {
                this.animateValue(parseFloat(String(newValue).replace(/,/g, '')) || 0);
            });
        },

        animateValue(newValue) {
            if (this.isAnimating) return;

            const start = parseFloat(this.displayValue) || 0;
            const end = parseFloat(newValue) || 0;

            if (start === end) return;

            this.isAnimating = true;
            this.targetValue = end;

            const duration = this.$wire.size === 'tv' ? 800 : 500;
            const startTime = Date.now();

            const animate = () => {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(elapsed / duration, 1);

                const eased = 1 - Math.pow(1 - progress, 3);

                const current = start + (end - start) * eased;
                this.displayValue = Math.round(current);

                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    this.displayValue = end;
                    this.isAnimating = false;
                }
            };

            requestAnimationFrame(animate);
        }
    }));
</script>
@endscript
