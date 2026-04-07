<div x-data="{
    startTime: null,
    visibleSeconds: 0,
    isVisible: true,
    timer: null,

    init() {
        this.startTime = Date.now();
        this.timer = setInterval(() => {
            if (this.isVisible) {
                this.visibleSeconds++;
            }
        }, 1000);

        document.addEventListener('visibilitychange', () => {
            this.isVisible = !document.hidden;
        });

        window.addEventListener('beforeunload', () => { this.sendBeacon(); });

        document.addEventListener('livewire:navigate', () => { this.sendBeacon(); this.reset(); });
    },

    reset() {
        this.visibleSeconds = 0;
        this.startTime = Date.now();
    },

    sendBeacon() {
        if (this.visibleSeconds < 1) return;

        navigator.sendBeacon('{{ route("demo.analytics.beacon") }}', new URLSearchParams({
            _token: '{{ csrf_token() }}',
            page: window.location.pathname,
            seconds: this.visibleSeconds,
            route: document.head.querySelector('meta[name=route-name]')?.content || ''
        }));
    },

    destroy() {
        clearInterval(this.timer);
    }
}" x-on:remove.window="sendBeacon()" class="hidden"></div>
