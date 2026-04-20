<div class="space-y-6">
    @php
        $timezones = collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->all();
    @endphp

    <x-card>
        <x-slot:title>Organization Information</x-slot:title>

        <div class="space-y-4">
            <x-input
                label="Organization Name"
                wire:model="organization_name"
                icon="phosphor-buildings"
                placeholder="e.g., Springfield Amateur Radio Club"
                required
            />

            <x-input
                label="Organization Callsign"
                wire:model="organization_callsign"
                icon="phosphor-cell-signal-high"
                placeholder="e.g., W1ABC"
                hint="Club station callsign (3-10 uppercase letters/numbers)"
            />

            <x-input
                label="Organization Email"
                type="email"
                wire:model="organization_email"
                icon="phosphor-envelope"
                placeholder="e.g., info@example.org"
                hint="Club contact email"
            />

            <x-input
                label="Organization Phone"
                type="tel"
                wire:model="organization_phone"
                icon="phosphor-phone"
                placeholder="e.g., (555) 123-4567"
                hint="Club contact phone number"
            />
        </div>
    </x-card>

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

            <x-alert icon="phosphor-eye" class="alert-info">
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
                icon="phosphor-clock"
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
            icon="phosphor-envelope"
            hint="Public contact email for the site"
        />
    </x-card>

    <x-card>
        <x-slot:title>Volunteer Hours</x-slot:title>

        <div class="space-y-3">
            <p class="text-sm opacity-70">
                How the reports page totals volunteer hours when a person has overlapping shifts
                (for example, an event manager who is also a safety officer and an operator).
            </p>

            <label class="flex items-start gap-3 cursor-pointer">
                <input
                    type="radio"
                    class="radio radio-sm mt-0.5"
                    value="sum"
                    wire:model="volunteer_hours_mode"
                />
                <div>
                    <div class="font-medium">Count all hours (sum)</div>
                    <div class="text-sm opacity-70">Each role's hours count separately. Recognizes multi-role contribution.</div>
                </div>
            </label>

            <label class="flex items-start gap-3 cursor-pointer">
                <input
                    type="radio"
                    class="radio radio-sm mt-0.5"
                    value="wall_clock"
                    wire:model="volunteer_hours_mode"
                />
                <div>
                    <div class="font-medium">Wall-clock hours (merge overlaps)</div>
                    <div class="text-sm opacity-70">Overlapping shifts merge into the actual time the volunteer was on site.</div>
                </div>
            </label>
        </div>
    </x-card>

    <div class="flex justify-end">
        <x-button
            wire:click="save"
            class="btn-primary"
            icon="phosphor-check"
            spinner="save"
        >
            <span wire:loading.remove wire:target="save">Save Preferences</span>
            <span wire:loading wire:target="save">Saving...</span>
        </x-button>
    </div>
</div>
