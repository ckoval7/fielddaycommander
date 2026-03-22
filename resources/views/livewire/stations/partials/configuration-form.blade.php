{{-- Basic Information Card --}}
<x-card class="mb-6">
    <x-slot:title>Basic Information</x-slot:title>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Station Name --}}
        <x-input
            label="Station Name"
            wire:model.blur="name"
            icon="o-signal"
            placeholder="e.g., Station 1, Digital Station, Phone Tent"
            autocomplete="off"
            required
            hint="Must be unique within the event"
        />

        {{-- Event Selection --}}
        <x-select
            label="Event"
            wire:model.live="event_configuration_id"
            :options="$this->events"
            option-value="id"
            option-label="name"
            placeholder="Select event"
            icon="o-calendar"
            required
            :disabled="$stationId ? true : false"
            :hint="$stationId ? 'Event cannot be changed when editing' : 'Select the field day event'"
        />
    </div>
</x-card>

{{-- Station Type Flags Card --}}
<x-card class="mb-6">
    <x-slot:title>Station Type</x-slot:title>

    <div class="space-y-3">
        {{-- GOTA Station --}}
        <x-checkbox
            label="GOTA Station (Get On The Air)"
            wire:model="is_gota"
            hint="Special station for new operators. Only one GOTA station allowed per event."
            :disabled="!$this->allowsGota"
        />

        @if(!$this->allowsGota && $event_configuration_id)
            <x-alert icon="o-information-circle" class="alert-warning">
                The selected event's operating class does not allow a GOTA station.
            </x-alert>
        @endif

        {{-- VHF/UHF Only --}}
        <x-checkbox
            label="VHF/UHF Only"
            wire:model="is_vhf_only"
            hint="Station operates exclusively on VHF/UHF bands"
        />

        {{-- Satellite Station --}}
        <x-checkbox
            label="Satellite Station"
            wire:model="is_satellite"
            hint="Station configured for satellite communications"
        />
    </div>
</x-card>

{{-- Primary Radio Card --}}
<x-card class="mb-6">
    <x-slot:title>Primary Radio</x-slot:title>

    {{-- Searchable Radio Select --}}
    <x-choices
        label="Primary Radio"
        wire:model.live="radio_equipment_id"
        :options="$availableRadios"
        option-value="id"
        option-label="name"
        placeholder="Search by make or model..."
        hint="Select the main transceiver for this station"
        icon="o-radio"
        search-function="searchRadios"
        single
        searchable
        required
    />

    @if($radio_equipment_id)
        @php
            $selectedRadio = $availableRadios->firstWhere('id', $radio_equipment_id);
        @endphp
        @if($selectedRadio && isset($selectedRadio['power_output_watts']))
            <div class="mt-2">
                <x-alert icon="o-information-circle" class="alert-info">
                    Radio capability: {{ $selectedRadio['power_output_watts'] }}W
                </x-alert>
            </div>
        @endif
    @endif
</x-card>

{{-- Power Configuration Card --}}
<x-card class="mb-6">
    <x-slot:title>Power Configuration</x-slot:title>

    <div class="space-y-4">
        {{-- Max Power Output --}}
        <x-input
            label="Max Power Output (Watts)"
            wire:model.blur="max_power_watts"
            type="number"
            min="1"
            max="5000"
            icon="o-bolt"
            placeholder="Optional"
            hint="Default operating power for this station (operators can override)"
        />

        @if($this->maxPowerLimit && $max_power_watts)
            @if($max_power_watts > $this->maxPowerLimit)
                <x-alert icon="o-exclamation-triangle" class="alert-warning">
                    Warning: Power ({{ $max_power_watts }}W) exceeds event's operating class limit of {{ $this->maxPowerLimit }}W.
                    This may affect scoring.
                </x-alert>
            @else
                <x-alert icon="o-check-circle" class="alert-success">
                    Power level is within the event's operating class limit of {{ $this->maxPowerLimit }}W.
                </x-alert>
            @endif
        @endif

        {{-- Power Source Description --}}
        <x-textarea
            label="Power Source Description"
            wire:model.live.debounce.500ms="power_source_description"
            placeholder="e.g., Solar + Battery Bank, Commercial + Generator Backup, 100% Solar"
            hint="Optional - Describe the power sources for this station"
            rows="3"
        />

        <div class="text-sm text-base-content/70">
            <span class="font-medium">Examples:</span>
            <ul class="list-disc list-inside ml-2 mt-1 space-y-1">
                <li>Solar + Battery (for 5x QRP multiplier)</li>
                <li>Generator (backup power)</li>
                <li>Commercial (if available)</li>
                <li>Battery Bank (emergency power)</li>
            </ul>
        </div>
    </div>
</x-card>
