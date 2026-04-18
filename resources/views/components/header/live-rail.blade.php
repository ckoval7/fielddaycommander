@props([
    'eventName' => '',
    'targetTimestamp' => null,
    'serverTimestamp' => 0,
    'state' => 'active',
])

<div
    role="status"
    aria-live="polite"
    class="flex items-center gap-2.5 px-3.5 py-[5px] bg-base-300 border-b border-base-300 font-mono text-[11px] tracking-[0.3px]"
    x-data="{
        countdown: '',
        targetTs: {{ (int) $targetTimestamp }},
        serverTs: {{ (int) $serverTimestamp }},
        initRealTs: Math.floor(Date.now() / 1000),
        _interval: null,
        init() {
            this.tick();
            this._interval = setInterval(() => this.tick(), 1000);
        },
        destroy() { if (this._interval) clearInterval(this._interval); },
        effectiveNow() { return this.serverTs + (Math.floor(Date.now() / 1000) - this.initRealTs); },
        tick() {
            if (!this.targetTs) { this.countdown = ''; return; }
            const diff = this.targetTs - this.effectiveNow();
            if (diff <= 0) { this.countdown = '0s'; return; }
            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;
            if (days > 0) this.countdown = days + 'd ' + hours + 'h ' + minutes + 'm';
            else if (hours > 0) this.countdown = hours + 'h ' + minutes + 'm';
            else this.countdown = minutes + 'm ' + seconds + 's';
        },
    }"
>
    <span class="inline-flex items-center gap-1.5 text-success font-bold uppercase">
        <span class="w-1.5 h-1.5 rounded-full bg-success" style="animation: fdpulse 1.6s ease-in-out infinite"></span>
        LIVE
    </span>
    <span class="flex-1 min-w-0 truncate text-base-content/70">{{ $eventName }}</span>
    <span class="text-success font-bold whitespace-nowrap">T-<span x-text="countdown">{{-- ticks --}}</span></span>
</div>
