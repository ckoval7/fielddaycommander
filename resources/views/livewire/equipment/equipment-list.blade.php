{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>My Equipment</x-slot:title>

    <div class="p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">My Equipment</h1>
            <div class="flex gap-2">
                <x-button label="Add Equipment" icon="phosphor-plus" class="btn-primary btn-sm sm:btn-md" link="{{ route('equipment.create') }}" wire:navigate />
                @can('edit-any-equipment')
                    <x-button label="Add Club Equipment" icon="phosphor-buildings" class="btn-club btn-sm sm:btn-md" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
                @endcan
            </div>
        </div>

        <x-equipment.filters />

        <x-equipment.bulk-action-bar :selected-ids="$selectedIds" />

        <x-equipment.list
            :equipment="$equipment"
            :selectable="true"
            :show-actions="true"
            ownership="mine"
            empty-message="No equipment found. Add equipment or change the filters."
            empty-action-label="Add Equipment"
            :empty-action-route="route('equipment.create')"
        />
    </div>

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    @include('livewire.equipment.partials.commit-modal', ['showErrorList' => true])
    @include('livewire.equipment.partials.bulk-commit-modal')
    @include('livewire.equipment.partials.commitment-details-modal')
    @include('livewire.equipment.partials.update-notes-modal')
</div>
