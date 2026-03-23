<div
    wire:poll.visible.30s="updateComponent"
    x-data="{
        localTime: '',
        utcTime: '',
        countdown: '',
        targetTs: null,
        serverTs: 0,
        initRealTs: 0,
        tz: 'UTC',
        state: '',
        _interval: null,

        init() {
            this.targetTs = $wire.targetTimestamp;
            this.serverTs = $wire.serverTimestamp;
            this.initRealTs = Math.floor(Date.now() / 1000);
            this.tz = $wire.timezone;
            this.state = $wire.state;

            this.tick();
            this._interval = setInterval(() => this.tick(), 1000);

            $wire.$watch('targetTimestamp', (v) => {
                this.targetTs = v;
                this.serverTs = $wire.serverTimestamp;
                this.initRealTs = Math.floor(Date.now() / 1000);
            });
            $wire.$watch('state', (v) => { this.state = v; });
            $wire.$watch('timezone', (v) => { this.tz = v; });
        },

        destroy() {
            if (this._interval) clearInterval(this._interval);
        },

        effectiveNow() {
            return this.serverTs + (Math.floor(Date.now() / 1000) - this.initRealTs);
        },

        tick() {
            const now = new Date();
            try {
                this.localTime = now.toLocaleTimeString('en-US', {
                    timeZone: this.tz, hour12: false,
                    hour: '2-digit', minute: '2-digit', second: '2-digit'
                });
            } catch(e) {
                this.localTime = now.toLocaleTimeString('en-US', { hour12: false });
            }
            this.utcTime = now.toLocaleTimeString('en-US', {
                timeZone: 'UTC', hour12: false,
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });

            if (!this.targetTs) return;

            const effectiveNow = this.effectiveNow();
            const diff = this.state === 'ended'
                ? effectiveNow - this.targetTs
                : this.targetTs - effectiveNow;
            if (diff <= 0) return;

            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            let formatted;
            if (days > 0) {
                formatted = days + 'd ' + hours + 'h ' + minutes + 'm';
            } else if (hours > 0) {
                formatted = hours + 'h ' + minutes + 'm';
            } else {
                formatted = minutes + 'm ' + seconds + 's';
            }

            this.countdown = this.state === 'ended' ? formatted + ' ago' : formatted;
        }
    }"
    class="flex flex-col lg:flex-row items-start lg:items-baseline gap-3 lg:gap-4"
    aria-live="polite"
    aria-label="Event countdown timer"
>
    @if($event)
        {{-- Event Badge and Name --}}
        <div class="flex items-center gap-3">
            <span class="badge {{ $badgeClass }} badge-lg text-base font-bold flex items-center gap-2">
                @if($state === 'upcoming')
                    <x-icon name="o-calendar-days" class="w-4 h-4" />
                @elseif($state === 'active')
                    <x-icon name="o-play-circle" class="w-4 h-4" />
                @elseif($state === 'ended')
                    <x-icon name="o-check-badge" class="w-4 h-4" />
                @endif
                {{ $state === 'active' ? 'LIVE' : strtoupper($state) }}
            </span>
            <span class="text-xl lg:text-2xl font-bold {{ $textClass }}">
                {{ $event->name }}
            </span>
            @if($state === 'active')
                <span class="relative flex h-4 w-4">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-4 w-4 bg-success"></span>
                </span>
            @endif
        </div>

        {{-- Separator (desktop only) --}}
        <span class="hidden lg:inline text-2xl text-base-content/30">|</span>

        {{-- Countdown Label and Time --}}
        <div class="flex items-center gap-2">
            <span class="text-base lg:text-lg font-semibold">
                {{ $label }}{{ $state !== 'ended' ? ':' : '' }}
            </span>
            <span class="text-xl lg:text-2xl font-mono font-bold {{ $textClass }}" x-text="countdown">
                {{ $this->formattedCountdown }}{{ $state === 'ended' ? ' ago' : '' }}
            </span>
        </div>

        {{-- Separator (desktop only) --}}
        <span class="hidden lg:inline text-2xl text-base-content/30">|</span>

        {{-- Clocks --}}
        <div class="flex items-center gap-4 text-base lg:text-lg">
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">{{ $timezoneLabel }}:</span>
                <span class="font-mono font-bold text-xl lg:text-2xl" x-text="localTime"></span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">UTC:</span>
                <span class="font-mono font-bold text-xl lg:text-2xl" x-text="utcTime"></span>
            </div>
        </div>
    @endif
</div>
