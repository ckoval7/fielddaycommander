{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Contact Row Component

Desktop table row for a server-confirmed contact in the QSO log. Clicking (or
Enter on) a live row recalls it; a deleted row shows struck through with an Undo.

Props:
- $contact: Contact - The contact to show
- $number: int|null - Running QSO number, or null when not numbered
- $time: Carbon|null - Time to display (defaults to the UTC QSO time)
- $readonly: bool - Greyed out and not recallable (a contact from an earlier session)

Slots:
- $slot: Extra cells, after Section
--}}

@props(['contact', 'number' => null, 'time' => null, 'readonly' => false])

@php
    $time ??= $contact->qso_time;
@endphp

<tr wire:key="contact-{{ $contact->id }}"
    @if($contact->uuid)
        data-contact-uuid="{{ $contact->uuid }}"
    @endif
    @if($readonly)
        data-earlier-session
    @elseif(! $contact->trashed())
        @click="recallByContactId({{ $contact->id }})"
        @keydown.enter="recallByContactId({{ $contact->id }})"
        tabindex="0"
    @endif
    :aria-current="recalledContactId === {{ $contact->id }}"
    :class="{
        '!bg-primary/25': recalledContactId === {{ $contact->id }},
    }"
    {{ $attributes->class([
        'opacity-40 line-through' => $contact->trashed(),
        'opacity-50' => ! $contact->trashed() && ($contact->is_duplicate || $readonly),
        'cursor-pointer hover:bg-base-200' => ! $contact->trashed() && ! $readonly,
    ]) }}>
    <td class="font-mono">{{ $number ?? '-' }}</td>
    <td class="font-mono">{{ $time->format('H:i') }}</td>
    <td class="font-bold font-mono uppercase">
        {{ $contact->callsign }}
        @if($contact->trashed())
            <button
                wire:click="restoreContact({{ $contact->id }})"
                class="btn btn-ghost btn-xs ml-1"
                title="Undo delete"
            >
                Undo
            </button>
        @elseif($contact->is_duplicate)
            <x-badge value="DUPE" class="badge-xs badge-warning ml-1" />
        @endif
    </td>
    <td class="font-mono">{{ $contact->exchange_class }}</td>
    <td>{{ $contact->section->code ?? '-' }}</td>
    {{ $slot }}
</tr>
