@props(['filterCols' => 3])

<div class="grid grid-cols-1 md:grid-cols-{{ $filterCols }} gap-4 mb-6">
    <x-input
        label="Search"
        placeholder="Search by make, model, or serial number..."
        wire:model.live.debounce.300ms="search"
        icon="phosphor-magnifying-glass"
        clearable
    />

    {{ $slot }}

    <x-select
        label="Type"
        wire:model.live="typeFilter"
        :options="array_merge(
            [['value' => null, 'label' => 'All Types']],
            \App\Models\Equipment::typeOptions()
        )"
        option-value="value"
        option-label="label"
    />

    <x-select
        label="Status"
        wire:model.live="statusFilter"
        :options="[
            ['value' => null, 'label' => 'All Status'],
            ['value' => 'available', 'label' => 'Available'],
            ['value' => 'committed', 'label' => 'Committed'],
        ]"
        option-value="value"
        option-label="label"
    />
</div>
