<?php

use App\Models\User;

test('fails when the system admin account does not exist', function () {
    $this->artisan('app:admin-2fa-status')
        ->expectsOutput('System admin account not found!')
        ->assertExitCode(1);
});

test('reports enabled with the confirmation time', function () {
    $admin = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);
    $admin->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => '2026-10-01 12:00:00',
    ])->save();

    $this->artisan('app:admin-2fa-status')
        ->expectsOutput('2FA: Enabled')
        ->expectsOutput('Confirmed: 2026-10-01 12:00:00')
        ->assertSuccessful();
});

test('reports pending when a secret exists but is not confirmed', function () {
    $admin = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);
    $admin->forceFill(['two_factor_secret' => encrypt('secret')])->save();

    $this->artisan('app:admin-2fa-status')
        ->expectsOutput('2FA: Pending confirmation')
        ->assertSuccessful();
});

test('reports not set up when there is no secret', function () {
    User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);

    $this->artisan('app:admin-2fa-status')
        ->expectsOutput('2FA: Not set up')
        ->assertSuccessful();
});
