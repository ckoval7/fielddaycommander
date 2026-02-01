<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step {{ $step >= 1 ? 'step-primary' : '' }}">Admin Password</li>
        <li class="step {{ $step >= 2 ? 'step-primary' : '' }}">Site Branding</li>
        <li class="step {{ $step >= 3 ? 'step-primary' : '' }}">Preferences</li>
    </ul>

    <div class="mb-6">
        <h2 class="text-2xl font-bold">Admin Password Setup</h2>
    </div>

    <div class="space-y-6">
        <p class="text-lg text-center">Create a secure password for the system administrator account.</p>

        <form method="POST" action="{{ route('setup.step-1') }}" class="space-y-6">
            @csrf

            <div class="space-y-4">
                <x-alert icon="o-information-circle" class="alert-info">
                    <div>
                        <div class="font-bold">System Administrator Account</div>
                        <div class="text-sm">Callsign: <span class="font-mono">SYSTEM</span> | Email: <span class="font-mono">admin@localhost</span></div>
                    </div>
                </x-alert>

                <x-input
                    label="Set Admin Password"
                    type="password"
                    name="admin_password"
                    required
                    icon="o-lock-closed"
                    hint="Minimum 12 characters with uppercase, lowercase, numbers, and symbols"
                />

                <x-input
                    label="Confirm Password"
                    type="password"
                    name="admin_password_confirmation"
                    required
                    icon="o-lock-closed"
                />
            </div>

            <div class="flex justify-between">
                <div></div>
                <x-button type="submit" class="btn-primary" icon="o-arrow-right" icon-right>
                    Next: Site Branding
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
