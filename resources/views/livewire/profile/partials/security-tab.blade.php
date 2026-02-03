<div class="space-y-6">
    {{-- Password Change Section --}}
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Change Password</h3>

            @if($user->password_changed_at)
                <p class="text-sm text-gray-500">
                    Last changed: {{ $user->password_changed_at->format('F j, Y g:i A') }}
                </p>
            @endif

            <x-form wire:submit="changePassword">
                {{-- Current Password --}}
                <x-input
                    label="Current Password"
                    type="password"
                    wire:model="current_password"
                    required
                />

                {{-- New Password --}}
                <x-input
                    label="New Password"
                    type="password"
                    wire:model="password"
                    required
                    hint="Minimum 8 characters"
                />

                {{-- Confirm Password --}}
                <x-input
                    label="Confirm New Password"
                    type="password"
                    wire:model="password_confirmation"
                    required
                />

                <div class="card-actions justify-end">
                    <x-button type="submit" spinner="changePassword" class="btn-primary">
                        Change Password
                    </x-button>
                </div>
            </x-form>
        </div>
    </div>

    {{-- Two-Factor Authentication Section --}}
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Two-Factor Authentication</h3>

            @if($user->has2FAEnabled())
                <x-alert icon="o-check-circle" class="alert-success">
                    Two-factor authentication is enabled
                </x-alert>

                <p class="text-sm text-base-content/60">
                    Your account is protected with two-factor authentication.
                </p>

                {{-- Placeholder for 2FA management buttons --}}
                <div class="card-actions justify-end mt-4">
                    <x-button class="btn-outline">View Recovery Codes</x-button>
                    <x-button class="btn-outline">Regenerate Codes</x-button>
                </div>
            @else
                <x-alert icon="o-exclamation-triangle" class="alert-warning">
                    Two-factor authentication is not enabled
                </x-alert>

                <p class="text-sm text-base-content/60">
                    Enable two-factor authentication to add an extra layer of security to your account.
                </p>

                <div class="card-actions justify-end mt-4">
                    <x-button class="btn-primary">Enable 2FA</x-button>
                </div>
            @endif
        </div>
    </div>
</div>
