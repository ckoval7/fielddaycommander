{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Net Entry Row Component

Desktop table row for a net log entry in the shared QSO log window.
Clicking (or Enter on) a live row recalls it; a deleted row shows struck
through with an Undo. The wire:key and data-recall-value feed the net log's recall.

Props:
- $entry: NetLogEntry - The entry to show
--}}

@props(['entry'])

<tr wire:key="net-entry-{{ $entry->id }}"
    data-recall-value="{{ $entry->exchangeLine() }}"
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
    <td class="font-mono uppercase whitespace-nowrap">{{ $entry->toLabel() }}</td>
    <td class="font-bold font-mono uppercase whitespace-nowrap">{{ $entry->fromLabel() }}</td>
    <td class="break-words min-w-48">
        <x-logging.net-priority-badge :priority="$entry->priority" />
        <span class="whitespace-pre-line">{{ $entry->message }}</span>
        @if($entry->trashed())
            <button wire:click.stop="restoreEntry({{ $entry->id }})" class="btn btn-ghost btn-xs ml-1 no-underline" title="Undo delete">Undo</button>
        @endif
    </td>
    <td class="text-xs text-base-content/60 whitespace-nowrap">{{ $entry->user?->call_sign }}</td>
</tr>
