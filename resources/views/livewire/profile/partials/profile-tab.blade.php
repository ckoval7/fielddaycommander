<x-form wire:submit="saveProfile">
    <div class="space-y-6">
        {{-- Basic Information Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Basic Information</h3>

                {{-- Call Sign (Read-only) --}}
                <x-input
                    label="Call Sign"
                    wire:model="call_sign"
                    disabled
                    hint="Contact an administrator to change your call sign"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- First Name --}}
                    <x-input
                        label="First Name"
                        wire:model="first_name"
                        required
                    />

                    {{-- Last Name --}}
                    <x-input
                        label="Last Name"
                        wire:model="last_name"
                        required
                    />
                </div>

                {{-- Email --}}
                <x-input
                    label="Email"
                    type="email"
                    wire:model="email"
                    required
                    hint="Changing your email will require verification"
                />

                {{-- License Class --}}
                <x-select
                    label="License Class"
                    wire:model="license_class"
                    :options="[
                        ['value' => '', 'label' => 'Not specified'],
                        ['value' => 'Technician', 'label' => 'Technician'],
                        ['value' => 'General', 'label' => 'General'],
                        ['value' => 'Advanced', 'label' => 'Advanced'],
                        ['value' => 'Extra', 'label' => 'Extra'],
                    ]"
                    option-value="value"
                    option-label="label"
                />
            </div>
        </div>

        {{-- Preferences Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Preferences</h3>

                {{-- Timezone --}}
                <x-select
                    label="Preferred Timezone"
                    wire:model="preferred_timezone"
                    :options="collect(timezone_identifiers_list())->map(fn($tz) => ['value' => $tz, 'label' => $tz])->toArray()"
                    option-value="value"
                    option-label="label"
                    searchable
                    hint="Used for displaying timestamps in your local time"
                />

                {{-- Email Notifications --}}
                <div>
                    <div class="text-sm font-semibold mb-2">Email Notifications</div>

                    <div class="space-y-2">
                        {{-- Security Alerts (Always enabled) --}}
                        <div class="flex items-center gap-2">
                            <x-checkbox checked disabled />
                            <span>Security Alerts <span class="text-xs text-base-content/60">(always enabled)</span></span>
                            <x-icon name="o-information-circle" class="w-4 h-4 text-base-content/40"
                                x-tooltip="Security notifications cannot be disabled for your protection" />
                        </div>

                        {{-- Event Notifications --}}
                        <x-checkbox
                            label="Event Notifications"
                            wire:model="event_notifications"
                            hint="Event starting/ending reminders and station assignments"
                        />

                        {{-- System Announcements --}}
                        <x-checkbox
                            label="System Announcements"
                            wire:model="system_announcements"
                            hint="System maintenance and important updates"
                        />
                    </div>
                </div>

                <div class="card-actions justify-end mt-4">
                    <x-button type="submit" spinner="saveProfile" class="btn-primary">
                        Save Changes
                    </x-button>
                </div>
            </div>
        </div>
    </div>
</x-form>
