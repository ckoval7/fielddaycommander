{{--
 ProgressBar Widget View

Displays progress toward next 50-QSO milestone with:
- Progress bar with smooth animation
- Current/Target numbers with count-up
- Percentage
- Label
- TV size variant support
- Milestone celebration animation (50, 100, 150, etc. QSOs)

Size variants:
- normal: Standard dashboard view
- tv: Larger for kiosk/TV displays

Celebration features:
- Trophy icon with bounce animation
- "Milestone! X QSOs logged!" message
- Pulse-glow effect on progress bar
- Auto-dismisses after 5 seconds
- Tracks last seen milestone to prevent duplicate celebrations
--}}

<x-card
    class="h-full flex flex-col"
    shadow
    wire:poll.visible.10s
    x-data="progressBarWidget"
>
<div class="flex-1 flex flex-col gap-3 relative">
    {{-- Numbers Display --}}
    <div class="flex items-baseline justify-between gap-3">
        <div class="@if($size === 'tv') text-5xl @else text-4xl @endif font-black tabular-nums whitespace-nowrap min-w-0 truncate">
            <span x-text="displayCurrent"></span>
            <span class="@if($size === 'tv') text-xl @else text-base @endif font-normal text-base-content/50">
                / {{ number_format($data['target']) }}
            </span>
        </div>
        @if($showPercentage)
            <div class="@if($size === 'tv') text-xl @else text-sm @endif font-medium text-base-content/70 tabular-nums whitespace-nowrap flex-shrink-0">
                <span x-text="displayPercentage"></span>%
            </div>
        @endif
    </div>

    {{-- Progress Bar with Smooth Transition --}}
    <div class="relative @if($size === 'tv') h-10 @else h-5 @endif bg-base-200 rounded-full overflow-hidden">
        <div
            class="h-full bg-primary rounded-full @if($size === 'tv') transition-all duration-800 @else transition-all duration-500 @endif ease-out"
            :style="`width: ${displayPercentage}%`"
            ::class="{
                'shadow-lg shadow-primary/50': isAnimating && @js($size === 'tv'),
                'animate-pulse-glow': isCelebrating
            }"
        ></div>
    </div>

    {{-- Label --}}
    <div class="@if($size === 'tv') text-lg @else text-sm @endif text-center text-base-content/70">
        {{ $data['footer_label'] ?? 'Progress' }}
    </div>

    {{-- Milestone Celebration Overlay --}}
    @if($celebratesMilestones)
    <div
        x-show="isCelebrating"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-90"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-90"
        class="absolute inset-0 flex items-center justify-center bg-base-100/95 rounded-lg z-10"
        style="display: none;"
    >
        <div class="text-center @if($size === 'tv') space-y-4 @else space-y-2 @endif">
            {{-- Trophy Icon --}}
            <div class="flex justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="@if($size === 'tv') w-20 h-20 @else w-12 h-12 @endif text-primary animate-bounce">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />
                </svg>
            </div>

            {{-- Milestone Message --}}
            <div>
                <div class="@if($size === 'tv') text-3xl @else text-xl @endif font-bold text-primary">
                    Milestone!
                </div>
                <div class="@if($size === 'tv') text-xl @else text-base @endif font-semibold text-base-content mt-1">
                    <span x-text="displayCurrent"></span> {{ $data['unit_label'] ?? 'QSOs' }} logged!
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

    {{-- Last updated timestamp --}}
    <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
</x-card>

@script
<script>
    Alpine.data('progressBarWidget', () => ({
        displayCurrent: 0,
        displayPercentage: 0,
        isAnimating: false,
        isCelebrating: false,
        lastMilestone: 0,

        init() {
            this.displayCurrent = this.$wire.current;
            this.displayPercentage = this.$wire.percentage;

            this.$wire.$watch('current', (newCurrent) => {
                this.animateNumbers(newCurrent, this.$wire.percentage);
                this.celebrate(newCurrent);
            });
            this.$wire.$watch('percentage', (newPercentage) => {
                this.animateNumbers(this.$wire.current, newPercentage);
            });
        },

        animateNumbers(newCurrent, newPercentage) {
            if (this.isAnimating) return;

            const startCurrent = parseFloat(this.displayCurrent) || 0;
            const endCurrent = parseFloat(newCurrent) || 0;
            const startPercentage = parseFloat(this.displayPercentage) || 0;
            const endPercentage = parseFloat(newPercentage) || 0;

            if (startCurrent === endCurrent && startPercentage === endPercentage) return;

            this.isAnimating = true;

            const duration = this.$wire.size === 'tv' ? 800 : 500;
            const startTime = Date.now();

            const animate = () => {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(elapsed / duration, 1);

                const eased = 1 - Math.pow(1 - progress, 3);

                this.displayCurrent = Math.round(startCurrent + (endCurrent - startCurrent) * eased);
                this.displayPercentage = Math.round(startPercentage + (endPercentage - startPercentage) * eased);

                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    this.displayCurrent = endCurrent;
                    this.displayPercentage = endPercentage;
                    this.isAnimating = false;
                }
            };

            requestAnimationFrame(animate);
        },

        celebrate(current) {
            if (current > 0 && current % 50 === 0 && current !== this.lastMilestone) {
                this.isCelebrating = true;
                this.lastMilestone = current;
                setTimeout(() => {
                    this.isCelebrating = false;
                }, 5000);
            }
        }
    }));
</script>
@endscript
