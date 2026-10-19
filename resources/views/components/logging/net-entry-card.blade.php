{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Net Entry Card Component

Mobile card for a net log entry in the shared QSO log window. Tapping a live
entry recalls it; a deleted entry shows struck through with an Undo.

Props:
- $entry: NetLogEntry - The entry to show
--}}

@props(['entry'])

@php
    $badge = match ($entry->priority) {
        \App\Enums\NetPriority::Emergency => ['class' => 'badge-error', 'icon' => 'phosphor-siren'],
        \App\Enums\NetPriority::Priority => ['class' => 'badge-warning', 'icon' => 'phosphor-warning'],
        default => null,
    };
    $from = \App\Models\NetLogEntry::displayStation($entry->from_station, $entry->from_callsign);
    $to = \App\Models\NetLogEntry::displayStation($entry->to_station, $entry->to_callsign);
@endphp

@php
    $classes = 'w-full flex items-start justify-between gap-3 px-3 py-2 rounded-lg border border-base-300 bg-base-100 text-left';
@endphp

@if($entry->trashed())
    <div wire:key="net-card-{{ $entry->id }}" class="{{ $classes }} opacity-40 line-through">
        <div class="min-w-0">
            <div class="font-bold font-mono uppercase break-words">{{ $from }} <span class="font-normal text-base-content/50">&rarr;</span> {{ $to }}</div>
            <div class="text-sm break-words">
                <span class="whitespace-pre-line">{{ $entry->message }}</span>
                <button wire:click="restoreEntry({{ $entry->id }})" class="btn btn-ghost btn-xs ml-1 no-underline">Undo</button>
            </div>
        </div>
        <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ toLocalTime($entry->logged_at)->format('H:i') }}</span>
    </div>
@else
    <button
        type="button"
        wire:key="net-card-{{ $entry->id }}"
        @click="recallByContactId({{ $entry->id }})"
        :aria-current="recalledContactId === {{ $entry->id }}"
        :class="{ 'ring-2 ring-primary': recalledContactId === {{ $entry->id }} }"
        class="{{ $classes }}"
    >
        <div class="min-w-0">
            <div class="font-bold font-mono uppercase break-words">{{ $from }} <span class="font-normal text-base-content/50">&rarr;</span> {{ $to }}</div>
            <div class="text-sm break-words">
                @if($badge)
                    <span class="badge {{ $badge['class'] }} badge-xs gap-1 mr-1 align-middle">
                        <x-icon :name="$badge['icon']" class="w-3 h-3" />{{ $entry->priority->label() }}
                    </span>
                @endif
                <span class="whitespace-pre-line">{{ $entry->message }}</span>
            </div>
        </div>
        <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ toLocalTime($entry->logged_at)->format('H:i') }}</span>
    </button>
@endif
