{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Equipment Catalog</x-slot:title>

    <div class="p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">Equipment Catalog</h1>
            @can('edit-any-equipment')
                <x-button
                    label="Add Equipment for Owner"
                    icon="phosphor-plus"
                    class="btn-primary btn-sm sm:btn-md"
                    link="{{ route('equipment.create', $userFilter && $userFilter !== 'club' ? ['for_user' => $userFilter] : []) }}"
                    wire:navigate
                />
            @endcan
        </div>

        <x-equipment.filters :filterCols="4">
            <x-choices-offline
                label="Owner"
                wire:model.live="userFilter"
                :options="$this->userOptions"
                placeholder="Search owner..."
                single
                searchable
            />
        </x-equipment.filters>

        <x-equipment.list
            :equipment="$equipment"
            :show-actions="auth()->user()?->can('edit-any-equipment') ?? false"
            ownership="all"
            empty-message="No equipment found. Try changing the filters."
        />
    </div>

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    @can('edit-any-equipment')
        @include('livewire.equipment.partials.commit-modal')
        @include('livewire.equipment.partials.commitment-details-modal')
        @include('livewire.equipment.partials.update-notes-modal')
    @endcan
</div>
