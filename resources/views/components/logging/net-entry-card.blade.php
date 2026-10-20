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
    $deleted = $entry->trashed();
    $tag = $deleted ? 'div' : 'button';
@endphp

<{{ $tag }}
    wire:key="net-card-{{ $entry->id }}"
    @unless($deleted)
        type="button"
        @click="recallByContactId({{ $entry->id }})"
        :aria-current="recalledContactId === {{ $entry->id }}"
        :class="{ 'ring-2 ring-primary': recalledContactId === {{ $entry->id }} }"
    @endunless
    @class([
        'w-full flex items-start justify-between gap-3 px-3 py-2 rounded-lg border border-base-300 bg-base-100 text-left',
        'opacity-40 line-through' => $deleted,
    ])
>
    <div class="min-w-0">
        <div class="font-bold font-mono uppercase break-words">{{ $entry->toLabel() }} <span class="font-normal text-base-content/50">&larr;</span> {{ $entry->fromLabel() }}</div>
        <div class="text-sm break-words">
            <x-logging.net-priority-badge :priority="$entry->priority" />
            <span class="whitespace-pre-line">{{ $entry->message }}</span>
            @if($deleted)
                <button wire:click="restoreEntry({{ $entry->id }})" class="btn btn-ghost btn-xs ml-1 no-underline">Undo</button>
            @endif
        </div>
    </div>
    <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ toLocalTime($entry->logged_at)->format('H:i') }}</span>
</{{ $tag }}>
