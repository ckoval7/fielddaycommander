<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );

    $this->user = User::factory()->create([
        'call_sign' => 'W1AW',
        'password' => Hash::make('password123'),
    ]);
});

it('redirects to profile security tab when 2fa is required and user has no 2fa', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertRedirect(route('profile', ['tab' => 'security']));
});

it('allows access when 2fa is required and user has confirmed 2fa', function () {
    config(['auth-security.2fa_mode' => 'required']);

    app(EnableTwoFactorAuthentication::class)($this->user);
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk();
});

it('allows access to profile route when 2fa is required but not set up', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->actingAs($this->user)
        ->get(route('profile', ['tab' => 'security']))
        ->assertOk();
});

it('allows access to logout when 2fa is required but not set up', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect();
});

it('does not redirect when mode is optional', function () {
    config(['auth-security.2fa_mode' => 'optional']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk();
});

it('does not redirect when mode is disabled', function () {
    config(['auth-security.2fa_mode' => 'disabled']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk();
});

it('does not redirect guests', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->get('/login')
        ->assertOk();
});

it('does not redirect ajax requests when 2fa is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->actingAs($this->user)
        ->get('/', ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();
});

it('does not redirect json requests when 2fa is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->actingAs($this->user)
        ->getJson('/')
        ->assertOk();
});

it('blocks raw fortify disable endpoint when mode is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($this->user);
    $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->delete('/user/two-factor-authentication', ['password' => 'password123'])
        ->assertForbidden();
});

it('blocks raw fortify enable endpoint when mode is disabled', function () {
    config(['auth-security.2fa_mode' => 'disabled']);

    $this->actingAs($this->user)
        ->post('/user/two-factor-authentication', ['password' => 'password123'])
        ->assertForbidden();
});
