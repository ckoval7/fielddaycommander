{{--
ProgressBar Widget View

Displays progress toward next 50-QSO milestone with:
- Progress bar with smooth animation
- Current/Target numbers with count-up
- Percentage
- Label
- TV size variant support

Size variants:
- normal: Standard dashboard view
- tv: Larger for kiosk/TV displays
--}}

<x-card class="h-full" shadow>
<div
    class="flex flex-col gap-3"
    wire:poll.visible.10s
    x-data="{
        displayCurrent: @js($data['current']),
        displayPercentage: @js($data['percentage']),
        isAnimating: false,

        animateNumbers(newCurrent, newPercentage) {
            if (this.isAnimating) return;

            const startCurrent = parseFloat(this.displayCurrent) || 0;
            const endCurrent = parseFloat(newCurrent) || 0;
            const startPercentage = parseFloat(this.displayPercentage) || 0;
            const endPercentage = parseFloat(newPercentage) || 0;

            if (startCurrent === endCurrent && startPercentage === endPercentage) return;

            this.isAnimating = true;

            const duration = @js($size === 'tv' ? 800 : 500);
            const startTime = Date.now();

            const animate = () => {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(elapsed / duration, 1);

                // Ease out cubic
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
        }
    }"
    x-effect="animateNumbers(@js($data['current']), @js($data['percentage']))"
>
    {{-- Numbers Display --}}
    <div class="flex items-baseline justify-between">
        <div class="@if($size === 'tv') text-4xl @else text-2xl @endif font-bold">
            <span x-text="displayCurrent"></span>
            <span class="@if($size === 'tv') text-2xl @else text-base @endif font-normal text-base-content/70">
                / {{ $data['target'] }}
            </span>
        </div>
        <div class="@if($size === 'tv') text-xl @else text-sm @endif font-medium text-base-content/70">
            <span x-text="displayPercentage"></span>%
        </div>
    </div>

    {{-- Progress Bar with Smooth Transition --}}
    <div class="relative @if($size === 'tv') h-8 @else h-4 @endif bg-base-200 rounded-full overflow-hidden">
        <div
            class="h-full bg-primary rounded-full @if($size === 'tv') transition-all duration-800 @else transition-all duration-500 @endif ease-out"
            :style="`width: ${displayPercentage}%`"
            ::class="{ 'shadow-lg shadow-primary/50': isAnimating && @js($size === 'tv') }"
        ></div>
    </div>

    {{-- Label --}}
    <div class="@if($size === 'tv') text-lg @else text-sm @endif text-center text-base-content/70">
        To next milestone
    </div>
</div>
</x-card>
