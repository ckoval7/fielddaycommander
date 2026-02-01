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

            <div class="space-y-4">
                <div class="form-control w-full">
                    <label class="label">
                        <span class="label-text">Timezone <span class="text-error">*</span></span>
                    </label>
                    <select name="timezone" required class="select select-bordered w-full">
                        <option value="">Select timezone...</option>
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control w-full">
                    <label class="label">
                        <span class="label-text">Date Format <span class="text-error">*</span></span>
                    </label>
                    <select name="date_format" required class="select select-bordered w-full">
                        <option value="">Select format...</option>
                        <option value="Y-m-d">2026-02-01 (ISO)</option>
                        <option value="m/d/Y">02/01/2026 (US)</option>
                        <option value="d/m/Y">01/02/2026 (EU)</option>
                    </select>
                </div>

                <div class="form-control w-full">
                    <label class="label">
                        <span class="label-text">Time Format <span class="text-error">*</span></span>
                    </label>
                    <select name="time_format" required class="select select-bordered w-full">
                        <option value="">Select format...</option>
                        <option value="H:i">14:30 (24-hour)</option>
                        <option value="h:i A">02:30 PM (12-hour)</option>
                    </select>
                </div>

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
