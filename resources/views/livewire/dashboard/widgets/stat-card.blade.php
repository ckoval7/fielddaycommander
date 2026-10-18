{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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
        <x-card body-class="flex flex-col min-h-0" class="h-full flex flex-col p-6" shadow>
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
                    class="text-[clamp(2.5rem,3.4vw,4.5rem)] leading-none font-black tabular-nums whitespace-nowrap {{ $data['color'] }} transition-all duration-300 mt-4"
                    :class="{ 'scale-105': isAnimating }"
                    x-text="formattedValue"
                >{{ $data['value'] }}</div>

                {{-- Label --}}
                <div class="text-xl sm:text-2xl text-base-content/70 mt-2">
                    {{ $data['label'] }}
                </div>
            </div>
            <x-dashboard.updated-at :at="$data['last_updated_at'] ?? null" />
        </x-card>
    @else
        {{-- Normal Mode: Left-aligned stat display with icon badge and trend --}}
        <x-card body-class="flex flex-col min-h-0" class="h-full flex flex-col" shadow>
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
                    :class="{ 'scale-105': isAnimating }"
                    x-text="formattedValue"
                >{{ $data['value'] }}</div>

                {{-- Label --}}
                <div class="text-sm text-base-content/60 mt-1">
                    {{ $data['label'] }}
                </div>
            </div>
            <x-dashboard.updated-at :at="$data['last_updated_at'] ?? null" class="border-t border-base-content/5" />
        </x-card>
    @endif
</div>

@script
<script>
    Alpine.data('statCardWidget', () => ({
        displayValue: 0,
        decimals: 0,
        isAnimating: false,
        frameId: null,

        init() {
            this.displayValue = this.parse(this.$wire.statValue);
            this.decimals = this.countDecimals(this.$wire.statValue);

            this.$wire.$watch('statValue', (newValue) => {
                this.decimals = this.countDecimals(newValue);
                this.animateValue(this.parse(newValue));
            });
        },

        destroy() {
            if (this.frameId) cancelAnimationFrame(this.frameId);
        },

        get formattedValue() {
            return Number(this.displayValue).toLocaleString(undefined, {
                minimumFractionDigits: this.decimals,
                maximumFractionDigits: this.decimals,
            });
        },

        parse(value) {
            return parseFloat(String(value).replace(/,/g, '')) || 0;
        },

        countDecimals(value) {
            const fraction = String(value).split('.')[1];
            return fraction ? fraction.length : 0;
        },

        // A new value arriving mid-animation retargets from wherever the
        // display currently is, rather than being dropped.
        animateValue(end) {
            if (this.frameId) cancelAnimationFrame(this.frameId);

            const start = this.displayValue;

            if (start === end) {
                this.isAnimating = false;
                return;
            }

            this.isAnimating = true;

            const duration = this.$wire.size === 'tv' ? 800 : 500;
            const factor = 10 ** this.decimals;
            const startTime = performance.now();

            const animate = (timestamp) => {
                const progress = Math.min((timestamp - startTime) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                this.displayValue = Math.round((start + (end - start) * eased) * factor) / factor;

                if (progress < 1) {
                    this.frameId = requestAnimationFrame(animate);
                } else {
                    this.displayValue = end;
                    this.isAnimating = false;
                    this.frameId = null;
                }
            };

            this.frameId = requestAnimationFrame(animate);
        }
    }));
</script>
@endscript
