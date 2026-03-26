<div class="space-y-6">
    @php
        $timezones = collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->all();
    @endphp

    <x-card>
        <x-slot:title>Regional Settings</x-slot:title>

        <div class="space-y-4">
            <x-choices-offline
                label="Timezone"
                wire:model.live="timezone"
                :options="$timezones"
                placeholder="Search timezone..."
                single
                searchable
                required
            />

            <x-select
                label="Date Format"
                wire:model.live="date_format"
                :options="$this->dateFormats"
                required
            />

            <x-select
                label="Time Format"
                wire:model.live="time_format"
                :options="$this->timeFormats"
                required
            />

            <x-alert icon="o-eye" class="alert-info">
                <strong>Preview:</strong> {{ $this->preview }}
            </x-alert>
        </div>
    </x-card>

    <x-card>
        <x-slot:title>Event Settings</x-slot:title>

        <div class="space-y-4">
            <x-input
                label="Post-Event Grace Period (days)"
                type="number"
                wire:model="post_event_grace_period_days"
                icon="o-clock"
                hint="Number of days after an event ends that operators can still enter late contacts (e.g., paper logs). Set to 0 to disable."
                min="0"
                max="365"
            />

            <x-toggle
                label="Enable ICS-213 Message Format"
                wire:model="enable_ics213"
                hint="Allow logging ICS-213 General Messages in addition to ARRL Radiograms. Most Field Day operations only need radiograms."
            />
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
