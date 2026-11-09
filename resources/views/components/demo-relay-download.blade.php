{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Demo only: invites visitors to connect Field Day Commander Relay to their sandbox. --}}
@props(['linkRelays' => false])

@if (config('demo.enabled'))
    {{-- Mary's alert drops the slot when it has a title, so the title is part of the slot. --}}
    <x-alert icon="phosphor-plugs-connected" class="alert-info" data-test="demo-relay-download">
        <strong class="block">Try your own logger</strong>
        Download and install Field Day Commander Relay, a Windows program that sends contacts from N1MM Logger+, N3FJP, WSJT-X/JTDX or ADIF files, from
        <a href="https://github.com/ckoval7/fielddaycommander-relay/releases/latest" target="_blank" rel="noopener noreferrer" class="link font-semibold">the releases page</a>.
        @if ($linkRelays)
            Create a code on the <a href="{{ route('relays.index') }}" wire:navigate class="link font-semibold">Relays page</a>, enter
        @else
            Create a code below, enter
        @endif the server address and code in the relay, then log in your own software to see your contacts in this demo.
        This sandbox and its connections expire after 24 hours, so you'll need a code from a new demo to reconnect.
    </x-alert>
@endif
