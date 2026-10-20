{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Net Entry Row Component

Desktop table row for a net log entry in the shared QSO log window.
Clicking (or Enter on) a live row recalls it; a deleted row shows struck
through with an Undo. The data attributes feed the net log composer's recall.

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
@endphp

<tr wire:key="net-entry-{{ $entry->id }}"
    data-net-entry-id="{{ $entry->id }}"
    data-exchange="{{ $entry->exchangeLine() }}"
    data-deleted="{{ $entry->trashed() ? 'true' : 'false' }}"
    @if(! $entry->trashed())
        @click="recallByContactId({{ $entry->id }})"
        @keydown.enter="recallByContactId({{ $entry->id }})"
        tabindex="0"
    @endif
    :aria-current="recalledContactId === {{ $entry->id }}"
    :class="{ '!bg-primary/25': recalledContactId === {{ $entry->id }} }"
    @class([
        'opacity-40 line-through' => $entry->trashed(),
        'cursor-pointer hover:bg-base-200' => ! $entry->trashed(),
    ])>
    <td class="font-mono whitespace-nowrap">{{ toLocalTime($entry->logged_at)->format('H:i') }}</td>
    <td class="font-mono uppercase whitespace-nowrap">{{ \App\Models\NetLogEntry::displayStation($entry->to_station, $entry->to_callsign) }}</td>
    <td class="font-bold font-mono uppercase whitespace-nowrap">{{ \App\Models\NetLogEntry::displayStation($entry->from_station, $entry->from_callsign) }}</td>
    <td class="break-words min-w-48">
        @if($badge)
            <span class="badge {{ $badge['class'] }} badge-xs gap-1 mr-1 align-middle">
                <x-icon :name="$badge['icon']" class="w-3 h-3" />{{ $entry->priority->label() }}
            </span>
        @endif
        <span class="whitespace-pre-line">{{ $entry->message }}</span>
        @if($entry->trashed())
            <button wire:click.stop="restoreEntry({{ $entry->id }})" class="btn btn-ghost btn-xs ml-1 no-underline" title="Undo delete">Undo</button>
        @endif
    </td>
    <td class="text-xs text-base-content/60 whitespace-nowrap">{{ $entry->user?->call_sign }}</td>
</tr>
