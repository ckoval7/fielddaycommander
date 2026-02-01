<div class="space-y-6">
    <x-card>
        <x-slot:title>Regional Settings</x-slot:title>

        <div class="space-y-4">
            {{-- Timezone Select --}}
            <div>
                <label class="label">
                    <span class="label-text">Timezone <span class="text-error">*</span></span>
                </label>
                <select
                    wire:model.live="timezone"
                    class="select select-bordered w-full"
                    required
                >
                    <option value="">Select a timezone...</option>
                    @foreach(timezone_identifiers_list() as $tz)
                        <option value="{{ $tz }}" @selected($timezone === $tz)>
                            {{ str_replace('_', ' ', $tz) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Date Format Select --}}
            <div>
                <label class="label">
                    <span class="label-text">Date Format <span class="text-error">*</span></span>
                </label>
                <select
                    wire:model.live="date_format"
                    class="select select-bordered w-full"
                    required
                >
                    <option value="Y-m-d" @selected($date_format === 'Y-m-d')>
                        {{ now()->format('Y-m-d') }} (ISO 8601)
                    </option>
                    <option value="m/d/Y" @selected($date_format === 'm/d/Y')>
                        {{ now()->format('m/d/Y') }} (US Format)
                    </option>
                    <option value="d/m/Y" @selected($date_format === 'd/m/Y')>
                        {{ now()->format('d/m/Y') }} (EU Format)
                    </option>
                </select>
            </div>

            {{-- Time Format Select --}}
            <div>
                <label class="label">
                    <span class="label-text">Time Format <span class="text-error">*</span></span>
                </label>
                <select
                    wire:model.live="time_format"
                    class="select select-bordered w-full"
                    required
                >
                    <option value="H:i:s" @selected($time_format === 'H:i:s')>
                        {{ now()->format('H:i:s') }} (24-hour)
                    </option>
                    <option value="h:i:s A" @selected($time_format === 'h:i:s A')>
                        {{ now()->format('h:i:s A') }} (12-hour)
                    </option>
                </select>
            </div>

            <x-alert class="alert-info">
                <div class="text-sm">
                    <strong>Preview:</strong> {{ $this->preview }}
                </div>
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
