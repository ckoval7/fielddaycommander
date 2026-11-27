<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserInvitation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    Setting::where('key', 'setup_completed')->delete();
    config(['auth-security.audit_logging_enabled' => true]);

    $this->setupFile = tempnam(sys_get_temp_dir(), 'setup');
    $this->writeSetup = function (array $overrides = [], array $without = []): void {
        $data = array_replace_recursive([
            'organization' => ['name' => 'Example Amateur Radio Club', 'callsign' => 'w1aw', 'email' => 'club@example.org'],
            'timezone' => 'America/New_York',
            'system_password' => 'A-Strong-Setup-Pass-1!',
            'admin' => [
                'first_name' => 'Pat',
                'last_name' => 'Operator',
                'call_sign' => 'k1abc',
                'email' => 'pat@example.org',
                'invitation_token' => str_repeat('a', 64),
                'invitation_expires_at' => now()->addDays(7)->toIso8601String(),
            ],
        ], $overrides);

        foreach ($without as $key) {
            data_forget($data, $key);
        }

        file_put_contents($this->setupFile, json_encode($data));
    };
});

afterEach(function () {
    @unlink($this->setupFile);
});

test('completes setup with a named System Administrator', function () {
    ($this->writeSetup)();

    $this->artisan('setup:apply', ['file' => $this->setupFile])
        ->expectsOutputToContain(route('invitation.show', str_repeat('a', 64)))
        ->assertSuccessful();

    $admin = User::where('call_sign', 'K1ABC')->firstOrFail();
    expect($admin->hasRole('System Administrator'))->toBeTrue();

    $invitation = UserInvitation::where('user_id', $admin->id)->firstOrFail();
    expect($invitation->token)->toBe(str_repeat('a', 64))
        ->and($invitation->isValid())->toBeTrue();

    $system = User::where('call_sign', User::SYSTEM_CALL_SIGN)->firstOrFail();
    expect(Hash::check('A-Strong-Setup-Pass-1!', $system->password))->toBeTrue()
        ->and($system->getRoleNames()->all())->toBe(['Config Only']);

    $organization = Organization::firstOrFail();
    expect($organization->callsign)->toBe('W1AW')
        ->and((int) Setting::get('default_organization_id'))->toBe($organization->id)
        ->and(Setting::get('site_name'))->toBe('Example Amateur Radio Club')
        ->and(Setting::get('timezone'))->toBe('America/New_York')
        ->and(Setting::get('datetime_format'))->toBe('Y-m-d H:i:s')
        ->and(Setting::getBoolean('setup_completed'))->toBeTrue();

    expect(AuditLog::where('action', 'system.setup.completed')->sole()->new_values)
        ->toBe(['method' => 'setup:apply', 'admin' => 'K1ABC']);
});

test('the admin, their invitation token and the SYSTEM password are optional', function () {
    ($this->writeSetup)(without: ['admin', 'system_password']);

    $this->artisan('setup:apply', ['file' => $this->setupFile])
        ->expectsOutputToContain('user:sign-in-link')
        ->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Hash::check('ChangeMe123!', User::sole()->password))->toBeFalse();
});

test('generates an invitation that lasts 72 hours when the file gives none', function () {
    ($this->writeSetup)(without: ['admin.invitation_token', 'admin.invitation_expires_at']);

    $this->artisan('setup:apply', ['file' => $this->setupFile])->assertSuccessful();

    $invitation = UserInvitation::sole();
    expect(strlen($invitation->token))->toBe(64)
        ->and($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue();
});

test('replaces the seeded SYSTEM password', function () {
    User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN, 'password' => Hash::make('ChangeMe123!')]);
    ($this->writeSetup)();

    $this->artisan('setup:apply', ['file' => $this->setupFile])->assertSuccessful();

    expect(Hash::check('ChangeMe123!', User::where('call_sign', User::SYSTEM_CALL_SIGN)->sole()->password))->toBeFalse();
});

test('the admin can accept the invitation and sign in', function () {
    ($this->writeSetup)();
    $this->artisan('setup:apply', ['file' => $this->setupFile])->assertSuccessful();

    $this->post(route('invitation.accept', str_repeat('a', 64)), [
        'password' => 'A-Strong-Password-123!',
        'password_confirmation' => 'A-Strong-Password-123!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(User::where('call_sign', 'K1ABC')->first());
});

test('does nothing once setup is complete', function () {
    Setting::set('setup_completed', 'true');
    ($this->writeSetup)();

    $this->artisan('setup:apply', ['file' => $this->setupFile])->assertSuccessful();

    expect(User::where('call_sign', 'K1ABC')->exists())->toBeFalse()
        ->and(Organization::count())->toBe(0);
});

test('rejects an invalid setup file without setting anything up', function (array $overrides) {
    ($this->writeSetup)($overrides);

    $this->artisan('setup:apply', ['file' => $this->setupFile])->assertFailed();

    expect(Setting::getBoolean('setup_completed'))->toBeFalse()
        ->and(Organization::count())->toBe(0);
})->with([
    'weak SYSTEM password' => [['system_password' => 'short']],
    'short invitation token' => [['admin' => ['invitation_token' => 'abc']]],
    'expired invitation' => [['admin' => ['invitation_expires_at' => now()->subDay()->toIso8601String()]]],
    'admin named SYSTEM' => [['admin' => ['call_sign' => 'system']]],
    'bad timezone' => [['timezone' => 'Mars/Olympus']],
    'bad date format' => [['date_format' => 'D M Y']],
]);

test('fails on a missing file', function () {
    $this->artisan('setup:apply', ['file' => '/nonexistent/setup.json'])->assertFailed();
});

test('reports what is missing when the file is not a JSON object', function (string $contents) {
    file_put_contents($this->setupFile, $contents);

    $this->artisan('setup:apply', ['file' => $this->setupFile])
        ->expectsOutputToContain('required')
        ->assertFailed();

    expect(Organization::count())->toBe(0);
})->with(['null' => 'null', 'list' => '[1, 2]', 'string' => '"club"']);

test('fails on a file that is not JSON', function () {
    file_put_contents($this->setupFile, '{not json');

    $this->artisan('setup:apply', ['file' => $this->setupFile])
        ->expectsOutputToContain('is not valid JSON')
        ->assertFailed();
});
