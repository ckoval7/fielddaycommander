<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Profile\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $this->user = User::factory()->create([
        'call_sign' => 'W1AW',
        'password' => Hash::make('password123'),
    ]);
});

it('shows enable 2fa form when 2fa is not enabled', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertSee('Two-factor authentication is not enabled')
        ->assertSee('Enable 2FA');
});

it('requires password to enable 2fa', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->call('enableTwoFactor')
        ->assertHasErrors('enableTwoFactorPassword');
});

it('rejects wrong password when enabling 2fa', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'wrong-password')
        ->call('enableTwoFactor')
        ->assertHasErrors('enableTwoFactorPassword');
});

it('shows qr code after enabling 2fa', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true)
        ->assertSeeHtml('Scan the QR code');
});

it('cancelling 2fa setup cleans up the unconfirmed secret', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true)
        ->call('cancelTwoFactorSetup')
        ->assertSet('showingQrCode', false);

    $this->user->refresh();
    expect($this->user->two_factor_secret)->toBeNull();
});

it('can confirm two factor with valid code', function () {
    // Enable 2FA first
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();

    // Get a valid TOTP code
    $google2fa = app(Google2FA::class);
    $secret = decrypt($this->user->two_factor_secret);
    $validCode = $google2fa->getCurrentOtp($secret);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('twoFactorCode', $validCode)
        ->call('confirmTwoFactor')
        ->assertSet('showingQrCode', false)
        ->assertSet('showingRecoveryCodes', true)
        ->assertHasNoErrors();
});

it('can disable two factor authentication', function () {
    // Enable and confirm 2FA
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('disableTwoFactorPassword', 'password123')
        ->call('disableTwoFactor')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_secret)->toBeNull();
});

it('requires password to disable 2fa', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->call('disableTwoFactor')
        ->assertHasErrors('disableTwoFactorPassword');
});

it('requires password to view recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->call('showRecoveryCodes')
        ->assertHasErrors('recoveryCodesPassword');
});

it('can view recovery codes with correct password', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('recoveryCodesPassword', 'password123')
        ->call('showRecoveryCodes')
        ->assertSet('showingRecoveryCodes', true);
});

it('can regenerate recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $originalCodes = $this->user->two_factor_recovery_codes;

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('recoveryCodesPassword', 'password123')
        ->call('regenerateRecoveryCodes')
        ->assertSet('showingRecoveryCodes', true)
        ->assertSet('recoveryCodesPassword', '')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_recovery_codes)->not->toBe($originalCodes);
});

it('prevents enabling 2fa when mode is disabled', function () {
    config(['auth-security.2fa_mode' => 'disabled']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'password123')
        ->call('enableTwoFactor')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_secret)->toBeNull();
});

it('prevents disabling 2fa when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    // Enable and confirm 2FA
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('disableTwoFactorPassword', 'password123')
        ->call('disableTwoFactor')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_secret)->not->toBeNull();
});

it('allows enabling 2fa when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true);
});

it('allows enabling 2fa when mode is optional', function () {
    config(['auth-security.2fa_mode' => 'optional']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('enableTwoFactorPassword', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true);
});

it('hides 2fa section when mode is disabled', function () {
    config(['auth-security.2fa_mode' => 'disabled']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertDontSee('Two-Factor Authentication');
});

it('shows 2fa required banner when mode is required and 2fa not set up', function () {
    config(['auth-security.2fa_mode' => 'required']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertSee('Two-factor authentication is required');
});

it('hides disable button when mode is required and 2fa is enabled', function () {
    config(['auth-security.2fa_mode' => 'required']);

    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertDontSee('Disable 2FA');
});

it('shows 2fa section when mode is optional', function () {
    config(['auth-security.2fa_mode' => 'optional']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertSee('Two-Factor Authentication');
});

it('shows 2fa section when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertSee('Two-Factor Authentication');
});

it('prevents cancelling 2fa setup when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    // Start 2FA setup
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->call('cancelTwoFactorSetup');

    $this->user->refresh();
    expect($this->user->two_factor_secret)->not->toBeNull();
});

it('requires password to regenerate recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $originalCodes = $this->user->two_factor_recovery_codes;

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('recoveryCodesPassword', 'wrong-password')
        ->call('regenerateRecoveryCodes')
        ->assertHasErrors(['recoveryCodesPassword'])
        ->assertSet('showingRecoveryCodes', false);

    expect($this->user->fresh()->two_factor_recovery_codes)->toBe($originalCodes);
});

it('does not let the client reveal the qr code or recovery codes', function (string $property) {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();

    expect(fn () => Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set($property, true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['showingQrCode', 'showingRecoveryCodes']);

it('does not render the secret for a confirmed 2fa setup', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertDontSee(decrypt($this->user->two_factor_secret))
        ->assertDontSee($this->user->recoveryCodes()[0]);
});

it('cancelling setup does not disable a confirmed 2fa', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->call('cancelTwoFactorSetup');

    $this->user->refresh();
    expect($this->user->two_factor_secret)->not->toBeNull()
        ->and($this->user->two_factor_confirmed_at)->not->toBeNull();
});

it('can hide recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('recoveryCodesPassword', 'password123')
        ->call('showRecoveryCodes')
        ->assertSet('showingRecoveryCodes', true)
        ->call('hideRecoveryCodes')
        ->assertSet('showingRecoveryCodes', false);
});

it('gives each 2fa action its own password field', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->assertDontSeeHtml('wire:model="recoveryCodesPassword"')
        ->assertDontSeeHtml('wire:model="disableTwoFactorPassword"')
        ->call('startTwoFactorAction', 'disable')
        ->assertSet('confirmingTwoFactorAction', 'disable')
        ->assertSeeHtml('wire:model="disableTwoFactorPassword"')
        ->assertDontSeeHtml('wire:model="recoveryCodesPassword"')
        ->set('disableTwoFactorPassword', 'password123')
        ->call('startTwoFactorAction', 'regenerate-codes')
        ->assertSet('disableTwoFactorPassword', '')
        ->assertSeeHtml('wire:model="recoveryCodesPassword"')
        ->assertDontSeeHtml('wire:model="disableTwoFactorPassword"')
        ->set('recoveryCodesPassword', 'password123')
        ->call('disableTwoFactor')
        ->assertHasErrors('disableTwoFactorPassword');

    expect($this->user->fresh()->two_factor_secret)->not->toBeNull();
});

it('cancelling a 2fa action hides its password field', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->call('startTwoFactorAction', 'view-codes')
        ->set('recoveryCodesPassword', 'secret')
        ->call('cancelTwoFactorAction')
        ->assertSet('confirmingTwoFactorAction', null)
        ->assertSet('recoveryCodesPassword', '')
        ->assertDontSeeHtml('wire:model="recoveryCodesPassword"');
});

it('ignores unknown 2fa actions', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->call('startTwoFactorAction', 'bogus')
        ->assertSet('confirmingTwoFactorAction', null);
});

it('typing the change password field does not fill the enable 2fa field', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
        ->call('enableTwoFactor')
        ->assertHasErrors('enableTwoFactorPassword')
        ->assertSet('showingQrCode', false);
});
