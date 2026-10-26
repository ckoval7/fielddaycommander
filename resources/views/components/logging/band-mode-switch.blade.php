{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Band/Mode Switch Component

Band and mode pickers for the logging session bar. Picking a value with the
mouse or a phone's picker switches straight away. From the keyboard, arrows
only stage the change (closed selects fire change on every arrow press), so
Enter switches and Esc or leaving the pickers puts them back.

The enclosing Alpine scope must provide switchBandMode(bandId, modeId) and
switching.

Props:
- $session: OperatingSession - The session being logged
- $bands: Collection<Band> - Bands that can be picked
- $modes: Collection<Mode> - Modes that can be picked
- $shortcuts: bool - Whether this copy answers Alt+B / Alt+M (one copy per page)
--}}

@props(['session', 'bands', 'modes', 'shortcuts' => false])

<div
    {{ $attributes->class('flex items-center gap-2') }}
    x-data="{
        staged: false,
        viaKeys: false,
        commit() {
            this.staged = false;
            switchBandMode(this.$refs.band.value, this.$refs.mode.value);
        },
        revert() {
            this.staged = false;
            this.$refs.band.value = '{{ $session->band_id }}';
            this.$refs.mode.value = '{{ $session->mode_id }}';
        },
        onChange() {
            this.viaKeys ? (this.staged = true) : this.commit();
        },
        onKeydown(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.staged ? this.commit() : null;
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.revert();
                document.getElementById('exchange-input')?.focus();
            } else {
                this.viaKeys = event.key !== 'Tab';
            }
        },
    }"
    @pointerdown="viaKeys = false"
    @focusout="staged && ! $el.contains($event.relatedTarget) && revert()"
    @if($shortcuts)
        @keydown.window="
            if ($event.altKey && ! $event.ctrlKey && ! $event.metaKey && ($event.code === 'KeyB' || $event.code === 'KeyM')) {
                $event.preventDefault();
                ($event.code === 'KeyB' ? $refs.band : $refs.mode).focus();
            }
        "
    @endif
>
    <label class="contents">
        <span class="sr-only">Band</span>
        <select
            x-ref="band"
            @change="onChange()"
            @keydown="onKeydown($event)"
            :disabled="switching"
            class="select select-sm text-base w-[4.5rem] sm:w-24 font-mono font-semibold touch-manipulation"
            data-band-switch
        >
            @foreach($bands as $band)
                <option value="{{ $band->id }}" @selected($band->id === $session->band_id)>{{ $band->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="contents">
        <span class="sr-only">Mode</span>
        <select
            x-ref="mode"
            @change="onChange()"
            @keydown="onKeydown($event)"
            :disabled="switching"
            class="select select-sm text-base w-[7.25rem] sm:w-32 font-mono font-semibold touch-manipulation"
            data-mode-switch
        >
            @foreach($modes as $mode)
                <option value="{{ $mode->id }}" @selected($mode->id === $session->mode_id)>{{ $mode->name }}</option>
            @endforeach
        </select>
    </label>
    <span x-show="staged" x-cloak class="text-xs text-base-content/70 whitespace-nowrap">
        <kbd class="kbd kbd-xs">Enter</kbd> to switch
    </span>
    <span x-show="switching" x-cloak class="loading loading-spinner loading-xs" aria-label="Switching band and mode"></span>
</div>
