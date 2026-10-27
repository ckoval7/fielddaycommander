{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Per-item actions menu for the Equipment Catalog list --}}
@php($commitment = $item->activeCommitments->first())
<x-dropdown>
    <x-slot:trigger class="btn btn-sm btn-ghost" aria-label="Actions for {{ $item->make }} {{ $item->model }}">
                            <x-icon name="phosphor-dots-three-vertical" class="w-5 h-5" aria-hidden="true" />
                        </x-slot:trigger>

    <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />

    @if($commitment)
        <x-menu-separator />
        <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $commitment->id }})" />
        <x-menu-item title="Update Delivery Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $commitment->id }})" />
        <x-menu-separator />
        @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
            @if($status !== $commitment->status)
                <x-menu-item
                    title="Mark as {{ ucfirst(str_replace('_', ' ', $status)) }}"
                    wire:click="changeStatus({{ $commitment->id }}, '{{ $status }}')"
                />
            @endif
        @endforeach
    @else
        <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
    @endif

    <x-menu-separator />
    <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Delete '{{ $item->make }} {{ $item->model }}'?" />
</x-dropdown>
