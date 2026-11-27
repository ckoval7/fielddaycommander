{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div x-data="{
    startTime: null,
    visibleSeconds: 0,
    isVisible: true,
    timer: null,
    heartbeatTimer: null,
    lastHeartbeat: 0,

    init() {
        this.startTime = Date.now();
        this.timer = setInterval(() => {
            if (this.isVisible) {
                this.visibleSeconds++;
            }
        }, 1000);

        document.addEventListener('visibilitychange', () => {
            this.isVisible = !document.hidden;
            if (this.isVisible) this.heartbeat();
        });

        // Tell the server the sandbox is in use while this tab is on screen,
        // so reading without clicking doesn't count as idle. Hidden tabs
        // skip it, and frozen or closed ones can't send it at all.
        this.heartbeat();
        this.heartbeatTimer = setInterval(() => this.heartbeat(), 120000);

        window.addEventListener('beforeunload', () => { this.sendBeacon(); });

        document.addEventListener('livewire:navigate', () => { this.sendBeacon(); this.reset(); });
    },

    heartbeat() {
        if (document.hidden || Date.now() - this.lastHeartbeat < 60000) return;

        this.lastHeartbeat = Date.now();
        navigator.sendBeacon('{{ route("demo.heartbeat") }}', new URLSearchParams({
            _token: '{{ csrf_token() }}'
        }));
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
        clearInterval(this.heartbeatTimer);
    }
}" x-on:remove.window="sendBeacon()" class="hidden"></div>
