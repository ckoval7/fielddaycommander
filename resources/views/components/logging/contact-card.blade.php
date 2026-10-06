{{--
Contact Card Component

Mobile card for a server-confirmed contact in the QSO log. Tapping a live
contact recalls it; a deleted contact shows struck through with an Undo.

Props:
- $contact: Contact - The contact to show
- $time: Carbon|null - Time to display (defaults to the UTC QSO time)
- $details: array<int, string>|null - Detail line parts (defaults to class and section)
--}}

@props(['contact', 'time' => null, 'details' => null])

@php
    $time ??= $contact->qso_time;
    $details ??= [$contact->exchange_class, $contact->section->code ?? '—'];
@endphp

@if($contact->trashed())
    <div wire:key="card-{{ $contact->id }}"
        class="w-full flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-base-300 bg-base-100 opacity-40 line-through">
        <div class="min-w-0">
            <div class="font-bold font-mono uppercase text-lg truncate">
                {{ $contact->callsign }}
                <button wire:click="restoreContact({{ $contact->id }})" class="btn btn-ghost btn-xs ml-1 no-underline">Undo</button>
            </div>
            <div class="text-xs text-base-content/60 font-mono">{{ implode(' · ', $details) }}</div>
        </div>
        <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ $time->format('H:i') }}</span>
    </div>
@else
    <button
        type="button"
        wire:key="card-{{ $contact->id }}"
        @click="recallByContactId({{ $contact->id }})"
        :aria-current="recalledContactId === {{ $contact->id }}"
        :class="{ 'ring-2 ring-primary': recalledContactId === {{ $contact->id }} }"
        @class([
            'w-full flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-base-300 bg-base-100 text-left',
            'opacity-50' => $contact->is_duplicate,
        ])
    >
        <div class="min-w-0">
            <div class="font-bold font-mono uppercase text-lg truncate">
                {{ $contact->callsign }}
                @if($contact->is_duplicate)
                    <x-badge value="DUPE" class="badge-xs badge-warning ml-1" />
                @endif
            </div>
            <div class="text-xs text-base-content/60 font-mono">{{ implode(' · ', $details) }}</div>
        </div>
        <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ $time->format('H:i') }}</span>
    </button>
@endif
