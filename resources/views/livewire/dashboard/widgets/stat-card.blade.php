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
        {{-- TV Mode: Large display for kiosk/TV dashboards --}}
        <x-card class="h-full flex flex-col p-6 sm:p-8" shadow>
            <div class="flex-1 flex flex-col items-center justify-center">
                <x-icon
                    :name="$data['icon']"
                    class="w-16 h-16 sm:w-20 sm:h-20 {{ $data['color'] }} mb-4 transition-transform duration-300"
                    ::class="{ 'scale-110': isAnimating }"
                />

                <div class="text-center">
                    <div
                        class="text-4xl sm:text-5xl lg:text-6xl font-bold {{ $data['color'] }} mb-2 transition-all duration-300"
                        ::class="{ 'scale-105': isAnimating }"
                        x-text="displayValue"
                    ></div>
                    <div class="text-xl sm:text-2xl text-base-content/70">
                        {{ $data['label'] }}
                    </div>
                    @if(isset($data['trend']) && $data['trend'] !== 'stable')
                        <div class="flex items-center gap-2 text-lg mt-2 justify-center">
                            @if($data['trend'] === 'up')
                                <x-icon name="o-arrow-trending-up" class="w-6 h-6 text-success" />
                                <span class="text-success">+{{ number_format($data['change_amount']) }}</span>
                            @else
                                <x-icon name="o-arrow-trending-down" class="w-6 h-6 text-error" />
                                <span class="text-error">{{ number_format($data['change_amount']) }}</span>
                            @endif
                            <span class="text-base-content/60 text-sm">{{ $data['comparison_label'] }}</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="text-xs text-base-content/40 text-right mt-auto pt-2">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
        </x-card>
    @else
        {{-- Normal Mode: Big number stat display with animated value --}}
        <x-card class="h-full flex flex-col" shadow>
            <div class="flex flex-col items-center justify-center flex-1 py-2">
                <x-icon
                    :name="$data['icon']"
                    class="w-10 h-10 {{ $data['color'] }} mb-1 transition-transform duration-200"
                    ::class="{ 'scale-110': isAnimating }"
                />
                <div
                    class="text-5xl font-bold {{ $data['color'] }} transition-all duration-200"
                    ::class="{ 'scale-105': isAnimating }"
                    x-text="displayValue"
                ></div>
                <div class="text-sm text-base-content/70 mt-1">
                    {{ $data['label'] }}
                </div>
                @if(isset($data['trend']) && $data['trend'] !== 'stable')
                    <div class="flex items-center gap-1 text-sm mt-1">
                        @if($data['trend'] === 'up')
                            <x-icon name="o-arrow-trending-up" class="w-4 h-4 text-success" />
                            <span class="text-success">+{{ number_format($data['change_amount']) }}</span>
                        @else
                            <x-icon name="o-arrow-trending-down" class="w-4 h-4 text-error" />
                            <span class="text-error">{{ number_format($data['change_amount']) }}</span>
                        @endif
                        <span class="text-base-content/60 text-xs">{{ $data['comparison_label'] }}</span>
                    </div>
                @endif
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
