{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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
        },

        destroy() {
            if (this._interval) clearInterval(this._interval);
        },

        effectiveNow() {
            return this.serverTs + (Math.floor(Date.now() / 1000) - this.initRealTs);
        },

        // Pulled from $wire each tick rather than via $wire.$watch: this lazy
        // component is initialised while Livewire clones its DOM, where Alpine
        // hands watchers a stub whose cleanup throws on destroy, silently
        // aborting wire:navigate away from every page.
        syncFromServer() {
            if ($wire.targetTimestamp !== this.targetTs) {
                this.targetTs = $wire.targetTimestamp;
                this.serverTs = $wire.serverTimestamp;
                this.initRealTs = Math.floor(Date.now() / 1000);
            }
            this.state = $wire.state;
            this.tz = $wire.timezone;
        },

        tick() {
            this.syncFromServer();
            const now = new Date(this.effectiveNow() * 1000);
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
    class="w-full"
>
    @include('livewire.components.partials.event-countdown-display', ['formattedCountdown' => $this->formattedCountdown])
</div>
