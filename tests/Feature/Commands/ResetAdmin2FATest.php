<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['auth-security.audit_logging_enabled' => true]);
});

function createSystemAdminWith2FA(): User
{
    $admin = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);
    $admin->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

test('fails when the system admin account does not exist', function () {
    $this->artisan('app:reset-admin-2fa')
        ->expectsOutput('System admin account not found!')
        ->assertExitCode(1);

    expect(AuditLog::count())->toBe(0);
});

test('does nothing when the confirmation is declined', function () {
    $admin = createSystemAdminWith2FA();

    $this->artisan('app:reset-admin-2fa')
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutput('Cancelled.')
        ->assertSuccessful();

    $admin->refresh();

    expect($admin->two_factor_secret)->not->toBeNull()
        ->and($admin->two_factor_confirmed_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'admin.2fa.reset')->exists())->toBeFalse();
});

test('clears the 2FA factor and writes a critical audit entry when confirmed', function () {
    $admin = createSystemAdminWith2FA();

    $this->artisan('app:reset-admin-2fa', ['--reason' => 'Lost phone'])
        ->expectsConfirmation('Continue?', 'yes')
        ->expectsOutput('✓ Admin 2FA has been reset')
        ->assertSuccessful();

    $admin->refresh();

    expect($admin->two_factor_secret)->toBeNull()
        ->and($admin->two_factor_recovery_codes)->toBeNull()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and($admin->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $log = AuditLog::where('action', 'admin.2fa.reset')->sole();

    expect($log->user_id)->toBe($admin->id)
        ->and($log->is_critical)->toBeTrue()
        ->and($log->new_values)->toBe(['reason' => 'Lost phone']);
});

test('uses the default reason when none is given', function () {
    createSystemAdminWith2FA();

    $this->artisan('app:reset-admin-2fa')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertSuccessful();

    expect(AuditLog::where('action', 'admin.2fa.reset')->sole()->new_values)
        ->toBe(['reason' => 'Emergency 2FA reset']);
});

test('the admin can log in without a 2FA challenge after the reset', function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $admin = createSystemAdminWith2FA();
    $admin->forceFill(['password' => 'password'])->save();

    $this->artisan('app:reset-admin-2fa')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertSuccessful();

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertSessionMissing('login.id');

    $this->assertAuthenticatedAs($admin);
});

test('other users keep their 2FA', function () {
    createSystemAdminWith2FA();
    $operator = User::factory()->create();
    $operator->forceFill([
        'two_factor_secret' => encrypt('operator-secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->artisan('app:reset-admin-2fa')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertSuccessful();

    expect($operator->refresh()->two_factor_secret)->not->toBeNull();
});
