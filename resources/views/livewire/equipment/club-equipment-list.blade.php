{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Club Equipment</x-slot:title>

    <div class="p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">Club Equipment</h1>
            @can('edit-any-equipment')
                <x-button label="Add Club Equipment" icon="phosphor-plus" class="btn-primary btn-sm sm:btn-md" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
            @endcan
        </div>

        <x-equipment.filters />

        @can('edit-any-equipment')
            <x-equipment.bulk-action-bar :selected-ids="$selectedIds" />
        @endcan

        @php($canEditAny = auth()->user()?->can('edit-any-equipment') ?? false)
        <x-equipment.list
            :equipment="$equipment"
            :selectable="$canEditAny"
            :show-actions="$canEditAny"
            ownership="club"
            empty-message="No club equipment found. Try changing the filters."
        />
    </div>

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    @can('edit-any-equipment')
        @include('livewire.equipment.partials.commit-modal')
        @include('livewire.equipment.partials.bulk-commit-modal')
        @include('livewire.equipment.partials.commitment-details-modal')
        @include('livewire.equipment.partials.update-notes-modal')
    @endcan
</div>
