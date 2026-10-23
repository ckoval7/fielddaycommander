{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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
                    autocomplete="current-password"
                    required
                />

                {{-- New Password --}}
                <x-input
                    label="New Password"
                    type="password"
                    wire:model="password"
                    autocomplete="new-password"
                    required
                    hint="Minimum 8 characters"
                />

                {{-- Confirm Password --}}
                <x-input
                    label="Confirm New Password"
                    type="password"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
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
                    Set up two-factor authentication to continue using Field Day Commander.
                </x-alert>
            @endif

            @if($showingQrCode && $user->two_factor_secret && ! $user->two_factor_confirmed_at)
                <x-alert icon="phosphor-device-mobile" class="alert-info">
                    Scan this QR code with your authenticator app, or enter the key below.
                </x-alert>

                <div class="flex justify-center my-4">
                    <div class="bg-white p-4 rounded-lg inline-block">
                        {!! auth()->user()->twoFactorQrCodeSvg() !!}
                    </div>
                </div>

                <p class="text-xs text-base-content/60 text-center mb-4">
                    Setup key: <code class="text-sm">{{ decrypt(auth()->user()->two_factor_secret) }}</code>
                </p>

                <x-form wire:submit="confirmTwoFactor">
                    <x-input
                        label="Authentication Code"
                        wire:model="twoFactorCode"
                        inputmode="numeric"
                        autocomplete="one-time-code"
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
                            Enable 2FA
                        </x-button>
                    </div>
                </x-form>
            @elseif($user->hasEnabledTwoFactorAuthentication())
                <x-alert icon="phosphor-check-circle" class="alert-success">
                    Two-factor authentication is enabled
                </x-alert>

                <p class="text-sm text-base-content/60">
                    Use an authentication code or a recovery code when you sign in.
                </p>

                {{-- Recovery codes display --}}
                @if($showingRecoveryCodes)
                    <div class="mt-4 p-4 bg-base-200 rounded-lg">
                        <p class="text-sm font-semibold mb-2">Recovery Codes</p>
                        <p class="text-xs text-base-content/60 mb-3">
                            Save these codes somewhere secure. Use one if you cannot access your authenticator app. Each code works once. Generating new codes replaces all old codes.
                        </p>
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            @foreach($user->recoveryCodes() as $code)
                                <div class="p-1">{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($confirmingTwoFactorAction === 'view-codes' || $confirmingTwoFactorAction === 'regenerate-codes')
                    <div class="flex items-end gap-2 mt-4" wire:key="recovery-codes-confirmation">
                        <div class="flex-1">
                            <x-input
                                label="Current Password"
                                type="password"
                                wire:model="recoveryCodesPassword"
                                autocomplete="current-password"
                                :placeholder="$confirmingTwoFactorAction === 'view-codes' ? 'Confirm password to view codes' : 'Enter password to replace old codes'"
                            />
                        </div>
                        @if($confirmingTwoFactorAction === 'view-codes')
                            <x-button wire:click="showRecoveryCodes" spinner="showRecoveryCodes" class="btn-primary">
                                View Recovery Codes
                            </x-button>
                        @else
                            <x-button wire:click="regenerateRecoveryCodes" spinner="regenerateRecoveryCodes" class="btn-primary">
                                Regenerate Codes
                            </x-button>
                        @endif
                        <x-button wire:click="cancelTwoFactorAction" class="btn-ghost">
                            Cancel
                        </x-button>
                    </div>
                @elseif($confirmingTwoFactorAction === 'disable' && config('auth-security.2fa_mode') !== 'required')
                    <div class="flex items-end gap-2 mt-4" wire:key="disable-two-factor-confirmation">
                        <div class="flex-1">
                            <x-input
                                label="Current Password"
                                type="password"
                                wire:model="disableTwoFactorPassword"
                                autocomplete="current-password"
                                placeholder="Confirm password to disable"
                            />
                        </div>
                        <x-button wire:click="disableTwoFactor" spinner="disableTwoFactor" class="btn-error">
                            Disable 2FA
                        </x-button>
                        <x-button wire:click="cancelTwoFactorAction" class="btn-ghost">
                            Cancel
                        </x-button>
                    </div>
                @else
                    <div class="card-actions justify-end mt-4">
                        @if(! $showingRecoveryCodes)
                            <x-button wire:click="startTwoFactorAction('view-codes')" class="btn-outline">
                                View Recovery Codes
                            </x-button>
                        @else
                            <x-button wire:click="startTwoFactorAction('regenerate-codes')" class="btn-outline">
                                Regenerate Codes
                            </x-button>
                            <x-button wire:click="hideRecoveryCodes" class="btn-ghost">
                                Hide Codes
                            </x-button>
                        @endif

                        @if(config('auth-security.2fa_mode') !== 'required')
                            <x-button wire:click="startTwoFactorAction('disable')" class="btn-error btn-outline">
                                Disable 2FA
                            </x-button>
                        @endif
                    </div>
                @endif
            @else
                @if(config('auth-security.2fa_mode') !== 'required')
                <x-alert icon="phosphor-warning" class="alert-warning">
                    Two-factor authentication is not enabled
                </x-alert>
                @endif

                <p class="text-sm text-base-content/60">
                    Set up an authenticator app to get codes for signing in.
                </p>

                <div class="flex items-end gap-2 mt-4">
                    <div class="flex-1">
                        <x-input
                            label="Current Password"
                            type="password"
                            wire:model="enableTwoFactorPassword"
                            autocomplete="current-password"
                            placeholder="Enter your current password"
                        />
                    </div>
                    <x-button wire:click="enableTwoFactor" spinner="enableTwoFactor" class="btn-primary">
                        Set Up 2FA
                    </x-button>
                </div>
            @endif
        </div>
    </div>
    @endif
</div>
