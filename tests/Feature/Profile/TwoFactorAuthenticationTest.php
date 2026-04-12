<?php

use App\Livewire\Profile\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
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
        ->assertHasErrors('current_password');
});

it('rejects wrong password when enabling 2fa', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'wrong-password')
        ->call('enableTwoFactor')
        ->assertHasErrors('current_password');
});

it('shows qr code after enabling 2fa', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true)
        ->assertSeeHtml('Scan the QR code');
});

it('cancelling 2fa setup cleans up the unconfirmed secret', function () {
    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
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
    $google2fa = app(\PragmaRX\Google2FA\Google2FA::class);
    $secret = decrypt($this->user->two_factor_secret);
    $validCode = $google2fa->getCurrentOtp($secret);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('showingQrCode', true)
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
        ->set('current_password', 'password123')
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
        ->assertHasErrors('current_password');
});

it('requires password to view recovery codes', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->call('showRecoveryCodes')
        ->assertHasErrors('current_password');
});

it('can view recovery codes with correct password', function () {
    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
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
        ->call('regenerateRecoveryCodes')
        ->assertSet('showingRecoveryCodes', true)
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_recovery_codes)->not->toBe($originalCodes);
});

it('prevents enabling 2fa when mode is disabled', function () {
    config(['auth-security.2fa_mode' => 'disabled']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
        ->call('enableTwoFactor')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->two_factor_secret)->toBeNull();
});

it('prevents disabling 2fa when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    // Enable and confirm 2FA
    app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
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
        ->set('current_password', 'password123')
        ->call('enableTwoFactor')
        ->assertSet('showingQrCode', true);
});

it('allows enabling 2fa when mode is optional', function () {
    config(['auth-security.2fa_mode' => 'optional']);

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('activeTab', 'security')
        ->set('current_password', 'password123')
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

    app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($this->user);
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
    app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($this->user);
    $this->user->refresh();

    Livewire::actingAs($this->user)
        ->test(UserProfile::class)
        ->set('showingQrCode', true)
        ->call('cancelTwoFactorSetup');

    $this->user->refresh();
    expect($this->user->two_factor_secret)->not->toBeNull();
});
