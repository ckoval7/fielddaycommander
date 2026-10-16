{{-- Per-item actions menu for the All User Catalogs list --}}
@php($commitment = $item->activeCommitments->first())
<x-dropdown>
    <x-slot:trigger>
        <x-button icon="phosphor-dots-three-vertical" class="btn-sm btn-ghost" aria-label="Actions for {{ $item->make }} {{ $item->model }}" />
    </x-slot:trigger>

    <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />

    @if($commitment)
        <x-menu-separator />
        <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $commitment->id }})" />
        <x-menu-item title="Update Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $commitment->id }})" />
        <x-menu-separator />
        @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
            @if($status !== $commitment->status)
                <x-menu-item
                    title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                    wire:click="changeStatus({{ $commitment->id }}, '{{ $status }}')"
                />
            @endif
        @endforeach
    @else
        <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
    @endif

    <x-menu-separator />
    <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
</x-dropdown>
