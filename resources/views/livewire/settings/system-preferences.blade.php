<div class="space-y-6">
    @php
        $timezones = collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->all();
        $dateFormats = [
            ['id' => 'Y-m-d', 'name' => now()->format('Y-m-d') . ' (ISO 8601)'],
            ['id' => 'm/d/Y', 'name' => now()->format('m/d/Y') . ' (US Format)'],
            ['id' => 'd/m/Y', 'name' => now()->format('d/m/Y') . ' (EU Format)'],
        ];
        $timeFormats = [
            ['id' => 'H:i:s', 'name' => now()->format('H:i:s') . ' (24-hour)'],
            ['id' => 'h:i:s A', 'name' => now()->format('h:i:s A') . ' (12-hour)'],
        ];
    @endphp

    <x-card>
        <x-slot:title>Regional Settings</x-slot:title>

        <div class="space-y-4">
            <x-select
                label="Timezone"
                wire:model.live="timezone"
                :options="$timezones"
                placeholder="Select a timezone..."
                required
            />

            <x-select
                label="Date Format"
                wire:model.live="date_format"
                :options="$dateFormats"
                required
            />

            <x-select
                label="Time Format"
                wire:model.live="time_format"
                :options="$timeFormats"
                required
            />

            <x-alert icon="o-eye" class="alert-info">
                <strong>Preview:</strong> {{ $this->preview }}
            </x-alert>
        </div>
    </x-card>

    <x-card>
        <x-slot:title>Contact Information</x-slot:title>

        <x-input
            label="Contact Email"
            type="email"
            wire:model="contact_email"
            icon="o-envelope"
            hint="Public contact email for the site"
        />
    </x-card>

    <x-card>
        <x-slot:title>API Settings</x-slot:title>

        <x-input
            label="Callsign Lookup API Key"
            type="text"
            wire:model="api_key"
            icon="o-key"
            hint="Optional - API key for callook.info integration (future feature)"
        />
    </x-card>

    <div class="flex justify-end">
        <x-button
            wire:click="save"
            class="btn-primary"
            icon="o-check"
            spinner="save"
        >
            <span wire:loading.remove wire:target="save">Save Preferences</span>
            <span wire:loading wire:target="save">Saving...</span>
        </x-button>
    </div>
</div>
