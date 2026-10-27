{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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

<x-card body-class="flex flex-col min-h-0"
    class="h-full flex flex-col"
    shadow
    wire:poll.visible.10s
    x-data="progressBarWidget({{ $celebratesMilestones ? 'true' : 'false' }}, {{ \App\Livewire\Dashboard\Widgets\ProgressBar::MILESTONE_INTERVAL }})"
>
<div class="flex-1 flex flex-col gap-3 relative">
    {{-- Numbers Display. On TV tiles the target wraps under the count
         rather than truncating it, and the percentage moves to the label row. --}}
    @if($size === 'tv')
        <div class="flex flex-wrap items-baseline gap-x-2 font-black tabular-nums leading-none">
            <span class="text-[clamp(1.75rem,2.6vw,3rem)]" x-text="Number(displayCurrent).toLocaleString()">{{ number_format($data['current']) }}</span>
            <span class="text-[clamp(1rem,1.1vw,1.25rem)] font-normal text-base-content/50 whitespace-nowrap">/ {{ number_format($data['target']) }}</span>
        </div>
    @else
        <div class="flex items-baseline justify-between gap-3">
            <div class="flex flex-wrap items-baseline gap-x-2 text-4xl font-black tabular-nums min-w-0">
                <span x-text="Number(displayCurrent).toLocaleString()">{{ number_format($data['current']) }}</span>
                <span class="text-base font-normal text-base-content/50 whitespace-nowrap">
                    / {{ number_format($data['target']) }}
                </span>
            </div>
            @if($showPercentage)
                <div class="text-sm font-medium text-base-content/70 tabular-nums whitespace-nowrap flex-shrink-0">
                    <span x-text="displayPercentage">{{ $data['percentage'] }}</span>%
                </div>
            @endif
        </div>
    @endif

    {{-- Progress Bar with Smooth Transition --}}
    <div class="relative @if($size === 'tv') h-[clamp(1rem,2vw,2.5rem)] @else h-5 @endif bg-base-200 rounded-full overflow-hidden">
        <div
            class="h-full bg-primary rounded-full @if($size === 'tv') transition-all duration-800 @else transition-all duration-500 @endif ease-out"
            :style="`width: ${displayPercentage}%`"
            :class="{
                'shadow-lg shadow-primary/50': isAnimating && @js($size === 'tv'),
                'animate-pulse-glow': isCelebrating
            }"
        ></div>
    </div>

    {{-- Label --}}
    <div class="@if($size === 'tv') text-lg @else text-sm @endif text-center text-base-content/70">
        @if($size === 'tv' && $showPercentage)
            <span class="font-semibold tabular-nums text-base-content"><span x-text="displayPercentage">{{ $data['percentage'] }}</span>%</span>
            <span class="text-base-content/40">·</span>
        @endif
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
                <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="@if($size === 'tv') w-20 h-20 @else w-12 h-12 @endif text-primary animate-bounce">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />
                </svg>
            </div>

            {{-- Milestone Message --}}
            <div>
                <div class="@if($size === 'tv') text-3xl @else text-xl @endif font-bold text-primary">
                    Milestone!
                </div>
                <div class="@if($size === 'tv') text-xl @else text-base @endif font-semibold text-base-content mt-1">
                    <span x-text="celebratedMilestone.toLocaleString()"></span> {{ $data['unit_label'] ?? 'QSOs' }} logged!
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

    {{-- Last updated timestamp --}}
    <x-dashboard.updated-at :at="$data['last_updated_at'] ?? null" class="border-t border-base-content/5" />
</x-card>

@script
<script>
    Alpine.data('progressBarWidget', (celebratesMilestones, milestoneInterval) => ({
        displayCurrent: 0,
        displayPercentage: 0,
        isAnimating: false,
        isCelebrating: false,
        celebratedMilestone: 0,
        lastMilestoneReached: 0,
        frameId: null,
        celebrationTimer: null,

        init() {
            this.displayCurrent = this.$wire.current;
            this.displayPercentage = this.$wire.percentage;
            this.lastMilestoneReached = this.milestoneReached(this.$wire.current);

            // Both properties change in the same response; one watcher reading
            // both avoids starting two competing animations.
            this.$wire.$watch('current', () => this.onUpdate());
            this.$wire.$watch('percentage', () => this.onUpdate());
        },

        destroy() {
            if (this.frameId) cancelAnimationFrame(this.frameId);
            if (this.celebrationTimer) clearTimeout(this.celebrationTimer);
        },

        onUpdate() {
            this.animateNumbers(this.$wire.current, this.$wire.percentage);
            this.celebrate(this.$wire.current);
        },

        milestoneReached(current) {
            return Math.floor((parseFloat(current) || 0) / milestoneInterval) * milestoneInterval;
        },

        // A new value arriving mid-animation retargets from the current
        // display rather than being dropped.
        animateNumbers(newCurrent, newPercentage) {
            const startCurrent = parseFloat(this.displayCurrent) || 0;
            const endCurrent = parseFloat(newCurrent) || 0;
            const startPercentage = parseFloat(this.displayPercentage) || 0;
            const endPercentage = parseFloat(newPercentage) || 0;

            if (startCurrent === endCurrent && startPercentage === endPercentage) return;

            if (this.frameId) cancelAnimationFrame(this.frameId);
            this.isAnimating = true;

            const duration = this.$wire.size === 'tv' ? 800 : 500;
            const startTime = performance.now();

            const animate = (timestamp) => {
                const progress = Math.min((timestamp - startTime) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                this.displayCurrent = Math.round(startCurrent + (endCurrent - startCurrent) * eased);
                this.displayPercentage = Math.round(startPercentage + (endPercentage - startPercentage) * eased);

                if (progress < 1) {
                    this.frameId = requestAnimationFrame(animate);
                } else {
                    this.displayCurrent = endCurrent;
                    this.displayPercentage = endPercentage;
                    this.isAnimating = false;
                    this.frameId = null;
                }
            };

            this.frameId = requestAnimationFrame(animate);
        },

        // Celebrate whenever a poll crosses a milestone, not only when it lands
        // exactly on one; busy logging routinely jumps 148 → 153 between polls.
        celebrate(current) {
            if (!celebratesMilestones) return;

            const reached = this.milestoneReached(current);

            if (reached > this.lastMilestoneReached) {
                this.celebratedMilestone = reached;
                this.isCelebrating = true;
                if (this.celebrationTimer) clearTimeout(this.celebrationTimer);
                this.celebrationTimer = setTimeout(() => {
                    this.isCelebrating = false;
                }, 5000);
            }

            this.lastMilestoneReached = reached;
        }
    }));
</script>
@endscript
