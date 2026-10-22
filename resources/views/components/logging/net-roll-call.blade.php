{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@props(['rollCall'])

@php
    $total = $rollCall->stations->count();
    $accounted = $rollCall->stations->filter->isAccounted()->count();
    $missing = $total - $accounted;
@endphp

<section class="bg-base-100 rounded-lg border-2 border-primary p-3 sm:p-4 space-y-3" data-roll-call aria-labelledby="roll-call-title">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <x-icon name="phosphor-list-checks" class="w-5 h-5 text-primary" />
        <h2 id="roll-call-title" class="font-semibold">Roll call</h2>
        <span class="text-sm text-base-content/70" data-roll-call-progress>{{ $accounted }} of {{ $total }} accounted for</span>
        <x-button
            label="End roll call"
            icon="phosphor-flag-checkered"
            class="btn-sm min-h-11 ml-auto max-sm:w-full"
            wire:click="endRollCall"
            :wire:confirm="$missing > 0 ? $missing.' '.($missing === 1 ? 'station is' : 'stations are').' not accounted for. End the roll call anyway?' : null"
            spinner="endRollCall"
        />
    </div>

    <p class="text-sm text-base-content/70">Tap a station when it answers, or log its answer below.</p>

    <ul class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
        @foreach($rollCall->stations as $station)
            <li wire:key="roll-call-station-{{ $station->id }}" data-roll-call-station>
                @if($station->isAccounted())
                    <div class="flex items-center gap-2 rounded-md border border-success bg-success/10 p-2 min-h-11">
                        <x-icon name="phosphor-check-circle" class="w-5 h-5 text-success shrink-0" />
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold break-words">{{ $station->name }}</div>
                            <div class="text-sm text-base-content/70">
                                Accounted for at <span class="font-mono">{{ toLocalTime($station->accounted_at)->format('H:i') }}</span>
                            </div>
                        </div>
                        <x-button
                            label="Undo"
                            class="btn-ghost btn-sm min-h-11 shrink-0"
                            wire:click="unmarkAccounted({{ $station->id }})"
                            spinner="unmarkAccounted({{ $station->id }})"
                            aria-label="Undo marking {{ $station->name }} as accounted for"
                        />
                    </div>
                @else
                    <button
                        type="button"
                        class="btn btn-outline w-full h-auto min-h-11 justify-start text-left touch-manipulation py-2"
                        wire:click="markAccounted({{ $station->id }})"
                        wire:loading.attr="disabled"
                        wire:target="markAccounted({{ $station->id }})"
                        aria-label="Mark {{ $station->name }} as accounted for"
                    >
                        <x-icon name="phosphor-circle" class="w-5 h-5 shrink-0" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold break-words">{{ $station->name }}</span>
                            <span class="block text-sm font-normal text-base-content/70">{{ $station->callsign ?? 'Waiting for reply' }}</span>
                        </span>
                    </button>
                @endif
            </li>
        @endforeach
    </ul>
</section>
