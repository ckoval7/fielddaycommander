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
    @if(config('auth-security.2fa_mode') !== 'disabled')
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Two-Factor Authentication</h3>

            @if(config('auth-security.2fa_mode') === 'required' && ! $user->hasEnabledTwoFactorAuthentication() && ! $showingQrCode)
                <x-alert icon="phosphor-warning" class="alert-error">
                    Two-factor authentication is required. You must set it up before you can use the application.
                </x-alert>
            @endif

            @if($showingQrCode)
                <x-alert icon="phosphor-device-mobile" class="alert-info">
                    Scan the QR code below with your authenticator app
                </x-alert>

                <div class="flex justify-center my-4">
                    <div class="bg-white p-4 rounded-lg inline-block">
                        {!! auth()->user()->twoFactorQrCodeSvg() !!}
                    </div>
                </div>

                <p class="text-xs text-base-content/60 text-center mb-4">
                    Or enter this key manually: <code class="text-sm">{{ decrypt(auth()->user()->two_factor_secret) }}</code>
                </p>

                <x-form wire:submit="confirmTwoFactor">
                    <x-input
                        label="Verification Code"
                        wire:model="twoFactorCode"
                        placeholder="Enter the 6-digit code from your app"
                        required
                    />

                    <div class="card-actions justify-end">
                        @if(config('auth-security.2fa_mode') !== 'required')
                        <x-button wire:click="cancelTwoFactorSetup" class="btn-ghost">
                            Cancel
                        </x-button>
                        @endif
                        <x-button type="submit" spinner="confirmTwoFactor" class="btn-primary">
                            Confirm & Enable
                        </x-button>
                    </div>
                </x-form>
            @elseif($user->hasEnabledTwoFactorAuthentication())
                <x-alert icon="phosphor-check-circle" class="alert-success">
                    Two-factor authentication is enabled
                </x-alert>

                <p class="text-sm text-base-content/60">
                    Your account is protected with two-factor authentication.
                </p>

                {{-- Recovery codes display --}}
                @if($showingRecoveryCodes)
                    <div class="mt-4 p-4 bg-base-200 rounded-lg">
                        <p class="text-sm font-semibold mb-2">Recovery Codes</p>
                        <p class="text-xs text-base-content/60 mb-3">
                            Store these codes in a secure location. Each code can only be used once.
                        </p>
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            @foreach($user->recoveryCodes() as $code)
                                <div class="p-1">{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="card-actions justify-end mt-4">
                    @if(! $showingRecoveryCodes)
                        <div class="flex items-end gap-2 w-full">
                            <div class="flex-1">
                                <x-input
                                    label="Current Password"
                                    type="password"
                                    wire:model="current_password"
                                    placeholder="Confirm password to view codes"
                                />
                            </div>
                            <x-button wire:click="showRecoveryCodes" spinner="showRecoveryCodes" class="btn-outline">
                                View Recovery Codes
                            </x-button>
                        </div>
                    @else
                        <x-button wire:click="regenerateRecoveryCodes" spinner="regenerateRecoveryCodes" class="btn-outline">
                            Regenerate Codes
                        </x-button>
                        <x-button wire:click="$set('showingRecoveryCodes', false)" class="btn-ghost">
                            Hide Codes
                        </x-button>
                    @endif
                </div>

                @if(config('auth-security.2fa_mode') !== 'required')
                <div class="divider"></div>

                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <x-input
                            label="Current Password"
                            type="password"
                            wire:model="current_password"
                            placeholder="Confirm password to disable"
                        />
                    </div>
                    <x-button wire:click="disableTwoFactor" spinner="disableTwoFactor" class="btn-error btn-outline">
                        Disable 2FA
                    </x-button>
                </div>
                @endif
            @else
                @if(config('auth-security.2fa_mode') !== 'required')
                <x-alert icon="phosphor-warning" class="alert-warning">
                    Two-factor authentication is not enabled
                </x-alert>
                @endif

                <p class="text-sm text-base-content/60">
                    Enable two-factor authentication to add an extra layer of security to your account.
                </p>

                <div class="flex items-end gap-2 mt-4">
                    <div class="flex-1">
                        <x-input
                            label="Current Password"
                            type="password"
                            wire:model="current_password"
                            placeholder="Confirm password to enable"
                        />
                    </div>
                    <x-button wire:click="enableTwoFactor" spinner="enableTwoFactor" class="btn-primary">
                        Enable 2FA
                    </x-button>
                </div>
            @endif
        </div>
    </div>
    @endif
</div>
