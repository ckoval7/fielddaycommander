{{--
Timer Widget View

Displays a countdown timer to the event end time.
Uses Alpine.js for client-side countdown updates every second.

Props from component:
- $data: Array with 'end_time', 'now', 'is_ended', 'label'
- $size: 'normal' or 'tv'
--}}

<div class="h-full">
    <x-card class="h-full flex flex-col items-center justify-center p-6 sm:p-8">
        @if ($data['is_ended'])
            {{-- Event has ended --}}
            <div class="text-center">
                <x-icon
                    name="o-clock"
                    class="w-12 h-12 sm:w-16 sm:h-16 {{ $size === 'tv' ? 'lg:w-24 lg:h-24' : '' }} text-base-content/50 mb-4"
                />
                <div class="text-2xl sm:text-3xl {{ $size === 'tv' ? 'lg:text-5xl' : '' }} font-bold text-base-content/70">
                    {{ $data['label'] }}
                </div>
            </div>
        @else
            {{-- Active countdown timer --}}
            <div
                x-data="countdown(@js($data['end_time']), @js($data['now']))"
                x-init="init()"
                class="text-center w-full"
            >
                <x-icon
                    name="o-clock"
                    class="w-12 h-12 sm:w-16 sm:h-16 {{ $size === 'tv' ? 'lg:w-24 lg:h-24' : '' }} text-primary mb-4"
                    ::class="{ 'text-warning': isWarning, 'text-error': isCritical }"
                />

                <div class="mb-2">
                    <div
                        class="text-3xl sm:text-4xl {{ $size === 'tv' ? 'lg:text-7xl' : '' }} font-mono font-bold text-primary"
                        ::class="{ 'text-warning': isWarning, 'text-error': isCritical }"
                        x-text="formattedTime"
                    >
                        --:--:--
                    </div>
                </div>

                <div class="text-lg sm:text-xl {{ $size === 'tv' ? 'lg:text-3xl' : '' }} text-base-content/70">
                    {{ $data['label'] }}
                </div>
            </div>
        @endif
    </x-card>
</div>

@script
<script>
Alpine.data('countdown', (endTimeIso, nowIso) => ({
    endTime: null,
    now: null,
    intervalId: null,
    formattedTime: '--:--:--',
    isWarning: false,
    isCritical: false,

    init() {
        this.endTime = new Date(endTimeIso);
        this.now = new Date(nowIso);

        // Calculate initial countdown
        this.updateCountdown();

        // Update every second
        this.intervalId = setInterval(() => {
            this.now = new Date(this.now.getTime() + 1000);
            this.updateCountdown();
        }, 1000);
    },

    destroy() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
    },

    updateCountdown() {
        const diff = this.endTime - this.now;

        if (diff <= 0) {
            this.formattedTime = 'Event Ended';
            if (this.intervalId) {
                clearInterval(this.intervalId);
            }
            return;
        }

        // Calculate time components
        const totalSeconds = Math.floor(diff / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        // Format time string
        if (days > 0) {
            // DD:HH:MM:SS format
            this.formattedTime = `${String(days).padStart(2, '0')}:${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        } else {
            // HH:MM:SS format
            this.formattedTime = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }

        // Set warning states
        const oneHour = 3600000; // 1 hour in milliseconds
        const thirtyMinutes = 1800000; // 30 minutes in milliseconds

        this.isCritical = diff < thirtyMinutes;
        this.isWarning = !this.isCritical && diff < oneHour;
    }
}));
</script>
@endscript
