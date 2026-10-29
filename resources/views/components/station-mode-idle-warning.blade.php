{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
    Station-mode idle warning: after a stretch with no keyboard, pointer or touch
    activity, count down and then end the operator's session unless dismissed.
--}}
<div
    x-data="{
        warnAfterMs: {{ \App\Support\StationMode::IDLE_WARNING_AFTER_SECONDS * 1000 }},
        countdownSeconds: {{ \App\Support\StationMode::IDLE_COUNTDOWN_SECONDS }},
        remaining: 0,
        warning: false,
        idleTimer: null,
        countdownTimer: null,
        activityEvents: ['keydown', 'pointerdown', 'pointermove', 'touchstart', 'wheel'],
        onActivity: null,
        init() {
            this.onActivity = () => { if (! this.warning) this.resetIdleTimer(); };
            this.activityEvents.forEach((type) => {
                window.addEventListener(type, this.onActivity, { passive: true });
            });
            this.resetIdleTimer();
        },
        resetIdleTimer() {
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => this.startWarning(), this.warnAfterMs);
        },
        startWarning() {
            this.warning = true;
            this.$refs.dialog.showModal();
            this.remaining = this.countdownSeconds;
            clearInterval(this.countdownTimer);
            this.countdownTimer = setInterval(() => {
                this.remaining--;
                if (this.remaining <= 0) {
                    clearInterval(this.countdownTimer);
                    this.signOut();
                }
            }, 1000);
            this.$nextTick(() => this.$refs.stillHere.focus());
        },
        signOut() {
            globalThis.stampQueuedSessions?.(this.$refs.endSessionForm);
            this.$refs.endSessionForm.submit();
        },
        dismiss() {
            clearInterval(this.countdownTimer);
            this.warning = false;
            this.$refs.dialog.close();
            this.resetIdleTimer();
        },
        destroy() {
            this.activityEvents.forEach((type) => {
                window.removeEventListener(type, this.onActivity);
            });
            clearTimeout(this.idleTimer);
            clearInterval(this.countdownTimer);
        }
    }"
    data-station-mode-idle-warning
>
    <form x-ref="endSessionForm" method="POST" action="{{ route('station-mode.end') }}" class="hidden" data-navigate-ignore>
        @csrf
    </form>

    <dialog
        x-ref="dialog"
        class="modal"
        aria-labelledby="station-mode-idle-title"
        @cancel.prevent
    >
        <div class="modal-box text-center">
            <x-icon name="phosphor-timer" class="w-12 h-12 mx-auto text-warning" />
            <h3 id="station-mode-idle-title" class="font-bold text-lg mt-2">Are you still operating?</h3>
            <p class="mt-2 text-sm">
                This browser has been idle for {{ intdiv(\App\Support\StationMode::IDLE_WARNING_AFTER_SECONDS, 60) }} minutes.
                You will be signed out in
                <span class="font-mono font-bold" x-text="remaining"></span>
                seconds.
            </p>
            <div class="modal-action justify-center">
                <button type="button" class="btn btn-ghost" @click="signOut()">Sign Out Now</button>
                <button type="button" class="btn btn-primary" x-ref="stillHere" @click="dismiss()">Keep Session Open</button>
            </div>
        </div>
    </dialog>
</div>
