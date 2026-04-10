{{--
Timer Widget View

Displays a countdown timer to the event end time.
Uses Alpine.js for client-side countdown updates every second.

Props from component:
- $data: Array with 'end_time', 'now', 'is_ended', 'label'
- $size: 'normal' or 'tv'
--}}

<div class="h-full">
    <x-card class="h-full flex flex-col p-6 sm:p-8" shadow>
        <div class="flex-1 flex flex-col items-center justify-center">
            @if ($data['is_ended'])
                {{-- Event has ended --}}
                <div class="text-center">
                    <div class="text-sm text-base-content/60 mb-2 {{ $size === 'tv' ? 'text-xl' : '' }}">
                        {{ $data['label'] }}
                    </div>
                    <div class="font-mono font-black {{ $size === 'tv' ? 'text-5xl lg:text-7xl' : 'text-3xl sm:text-4xl' }} text-base-content/70">
                        00:00:00
                    </div>
                </div>
            @else
                {{-- Active countdown timer --}}
                <div
                    x-data="countdown(@js($data['end_time']), @js($data['now']))"
                    x-init="init()"
                    class="text-center w-full"
                >
                    {{-- Small label --}}
                    <div class="text-sm text-base-content/60 mb-2 {{ $size === 'tv' ? 'text-xl' : '' }}">
                        {{ $data['label'] }}
                    </div>

                    <div>
                        <div
                            class="font-mono font-black text-primary {{ $size === 'tv' ? 'text-6xl lg:text-8xl' : 'text-4xl sm:text-5xl' }}"
                            ::class="{ 'text-warning': isWarning, 'text-error': isCritical }"
                            x-text="formattedTime"
                        >
                            --:--:--
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Last updated timestamp --}}
        <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
    </x-card>
</div>

@script
<script>
Alpine.data('countdown', (endTimeIso, nowIso) => ({
    endTime: null,
    serverClientOffset: 0,
    intervalId: null,
    visibilityHandler: null,
    formattedTime: '--:--:--',
    isWarning: false,
    isCritical: false,

    init() {
        this.endTime = new Date(endTimeIso);
        // Anchor to server time at init to account for clock skew
        this.serverClientOffset = new Date(nowIso).getTime() - Date.now();

        this.updateCountdown();
        this.intervalId = setInterval(() => this.updateCountdown(), 1000);

        // Immediately resync when the tab becomes visible after being hidden,
        // since browsers throttle setInterval in background tabs
        this.visibilityHandler = () => {
            if (!document.hidden) this.updateCountdown();
        };
        document.addEventListener('visibilitychange', this.visibilityHandler);
    },

    destroy() {
        if (this.intervalId) clearInterval(this.intervalId);
        if (this.visibilityHandler) document.removeEventListener('visibilitychange', this.visibilityHandler);
    },

    effectiveNow() {
        return Date.now() + this.serverClientOffset;
    },

    updateCountdown() {
        const diff = this.endTime.getTime() - this.effectiveNow();

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
