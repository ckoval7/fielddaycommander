<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step @if($step >= 1) step-primary @endif">Admin Password</li>
        <li class="step @if($step >= 2) step-primary @endif">Site Branding</li>
        <li class="step @if($step >= 3) step-primary @endif">Preferences</li>
    </ul>

    <div class="mb-6">
        <h2 class="text-2xl font-bold">Step 3: System Preferences</h2>
    </div>

    <div class="space-y-6">
        <p class="text-center">Configure essential system settings.</p>

        <form method="POST" action="{{ route('setup.complete') }}" class="space-y-6">
            @csrf

            <div class="space-y-4">
                <x-select
                    label="Timezone"
                    name="timezone"
                    required
                    icon="o-globe-americas"
                    :options="collect(timezone_identifiers_list())->mapWithKeys(fn($tz) => [$tz => $tz])->toArray()"
                    searchable
                />

                <x-select
                    label="Date Format"
                    name="date_format"
                    required
                    icon="o-calendar"
                    :options="[
                        'Y-m-d' => '2026-02-01 (ISO)',
                        'm/d/Y' => '02/01/2026 (US)',
                        'd/m/Y' => '01/02/2026 (EU)',
                    ]"
                />

                <x-select
                    label="Time Format"
                    name="time_format"
                    required
                    icon="o-clock"
                    :options="[
                        'H:i' => '14:30 (24-hour)',
                        'h:i A' => '02:30 PM (12-hour)',
                    ]"
                />

                <x-input
                    label="Contact Email"
                    type="email"
                    name="contact_email"
                    icon="o-envelope"
                    hint="Optional - for public contact information"
                />
            </div>

            <div class="flex justify-between">
                <x-button
                    type="button"
                    onclick="window.location='{{ route('setup.branding') }}'"
                    class="btn-ghost"
                    icon="o-arrow-left"
                >
                    Back
                </x-button>

                <x-button type="submit" class="btn-success" icon="o-check-circle" icon-right>
                    Complete Setup
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
