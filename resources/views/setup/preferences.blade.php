<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step {{ $step >= 1 ? 'step-primary' : '' }}">Admin Password</li>
        <li class="step {{ $step >= 2 ? 'step-primary' : '' }}">Site Branding</li>
        <li class="step {{ $step >= 3 ? 'step-primary' : '' }}">Preferences</li>
    </ul>

    <div class="mb-6">
        <h2 class="text-2xl font-bold">Step 3: System Preferences</h2>
    </div>

    <div class="space-y-6">
        <p class="text-center">Configure essential system settings.</p>

        <form method="POST" action="{{ route('setup.complete') }}" class="space-y-6">
            @csrf

            @php
                $timezones = collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->all();
                $dateFormats = [
                    ['id' => 'Y-m-d', 'name' => now()->format('Y-m-d') . ' (ISO)'],
                    ['id' => 'm/d/Y', 'name' => now()->format('m/d/Y') . ' (US)'],
                    ['id' => 'd/m/Y', 'name' => now()->format('d/m/Y') . ' (EU)'],
                ];
                $timeFormats = [
                    ['id' => 'H:i', 'name' => now()->format('H:i') . ' (24-hour)'],
                    ['id' => 'h:i A', 'name' => now()->format('h:i A') . ' (12-hour)'],
                ];
            @endphp

            <div class="space-y-4">
                <x-select
                    label="Timezone"
                    name="timezone"
                    :options="$timezones"
                    placeholder="Select timezone..."
                    required
                />

                <x-select
                    label="Date Format"
                    name="date_format"
                    :options="$dateFormats"
                    placeholder="Select format..."
                    required
                />

                <x-select
                    label="Time Format"
                    name="time_format"
                    :options="$timeFormats"
                    placeholder="Select format..."
                    required
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

                <x-button type="submit" class="btn-success" icon-right="o-check-circle">
                    Complete Setup
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
