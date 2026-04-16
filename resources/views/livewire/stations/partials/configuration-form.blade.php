{{-- Basic Information Card --}}
<x-card class="mb-6">
    <x-slot:title>Basic Information</x-slot:title>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Station Name --}}
        <x-input
            label="Station Name"
            wire:model.blur="name"
            icon="phosphor-cell-signal-high"
            placeholder="e.g., Station 1, Digital Station, Phone Tent"
            autocomplete="off"
            required
            hint="Must be unique within the event"
        />

        {{-- Hostname (for external logger matching) --}}
        <x-input
            label="Hostname (NetBIOS Name)"
            wire:model.blur="hostname"
            icon="phosphor-desktop"
            placeholder="e.g., CONTEST-PC"
            autocomplete="off"
            maxlength="50"
            hint="Used for automatic station matching with external loggers like N1MM+"
        />

        {{-- Event Selection --}}
        <x-select
            label="Event"
            wire:model.live="event_configuration_id"
            :options="$this->events"
            option-value="id"
            option-label="name"
            placeholder="Select event"
            icon="phosphor-calendar"
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
        <p class="text-sm text-base-content/70">Select at most one station type, or leave all unchecked for a standard HF station.</p>

        {{-- GOTA Station --}}
        <x-checkbox
            label="GOTA Station (Get On The Air)"
            wire:model.live="is_gota"
            hint="Special station for new operators. Only one GOTA station allowed per event."
            :disabled="!$this->allowsGota"
        />

        @if(!$this->allowsGota && $event_configuration_id)
            <x-alert icon="phosphor-info" class="alert-warning">
                The selected event's operating class does not allow a GOTA station.
            </x-alert>
        @endif

        {{-- VHF/UHF Only --}}
        <x-checkbox
            label="VHF/UHF Only"
            wire:model.live="is_vhf_only"
            hint="Station operates exclusively on VHF/UHF bands"
        />

        {{-- Satellite Station --}}
        <x-checkbox
            label="Satellite Station"
            wire:model.live="is_satellite"
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
        icon="phosphor-radio"
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
                <x-alert icon="phosphor-info" class="alert-info">
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
            icon="phosphor-lightning"
            placeholder="Optional"
            hint="Default operating power for this station (operators can override)"
        />

        @if($this->maxPowerLimit && $max_power_watts)
            @if($max_power_watts > $this->maxPowerLimit)
                <x-alert icon="phosphor-warning" class="alert-warning">
                    <span class="font-semibold">Scoring impact:</span> This station's power ({{ $max_power_watts }}W) exceeds the event's {{ $this->maxPowerLimit }}W setting.
                    Per Field Day rules, the highest station power determines the multiplier for the <span class="font-semibold">entire entry</span>
                    @if($max_power_watts > 100)
                        &mdash; this will reduce it to 1&times;.
                    @elseif($this->maxPowerLimit <= 5)
                        &mdash; this will reduce it from 5&times; to 2&times;.
                    @else
                        &mdash; this may reduce your power multiplier.
                    @endif
                </x-alert>
            @else
                <x-alert icon="phosphor-check-circle" class="alert-success">
                    Power level is within the event's {{ $this->maxPowerLimit }}W limit.
                </x-alert>
            @endif
        @endif

        {{-- Power Source Selector --}}
        <x-select
            label="Power Source"
            wire:model.live="power_source"
            :options="collect(\App\Enums\PowerSource::cases())->map(fn($ps) => ['id' => $ps->value, 'name' => $ps->label()])"
            option-value="id"
            option-label="name"
            placeholder="Select power source..."
            icon="phosphor-battery-full"
            hint="Primary power source for this station"
        />

        @if($power_source)
            @php
                $ps = \App\Enums\PowerSource::tryFrom($power_source);
            @endphp
            @if($ps)
                <x-alert
                    icon="{{ $ps->isNaturalPower() ? 'o-sun' : ($ps->isEmergencyPower() ? 'o-bolt' : 'o-building-office') }}"
                    class="{{ $ps->isNaturalPower() ? 'alert-success' : ($ps->isEmergencyPower() ? 'alert-info' : 'alert-warning') }}"
                >
                    @if($ps->isNaturalPower())
                        Qualifies for emergency power bonus and natural power (5&times; QRP) multiplier.
                    @elseif($ps->isEmergencyPower())
                        Qualifies for emergency power bonus. Does not qualify for natural power (5&times; QRP) multiplier.
                    @else
                        Does not qualify for emergency power bonus or natural power multiplier.
                    @endif
                </x-alert>
            @endif
        @endif

        {{-- Power Source Notes --}}
        <x-textarea
            label="Power Source Notes"
            wire:model.live.debounce.500ms="power_source_description"
            placeholder="e.g., 200Ah LiFePO4 bank, Honda EU2200i, rooftop solar array details"
            hint="Optional details about the power setup"
            rows="2"
        />
    </div>
</x-card>
