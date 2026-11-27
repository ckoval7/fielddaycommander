<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
    config(['hosted.enabled' => true]);

    $this->bootstrapFile = tempnam(sys_get_temp_dir(), 'tenant');
    $this->writeBootstrap = function (array $overrides = []): void {
        file_put_contents($this->bootstrapFile, json_encode(array_replace_recursive([
            'organization' => ['name' => 'Example Amateur Radio Club', 'callsign' => 'w1aw', 'email' => 'club@example.org'],
            'timezone' => 'America/New_York',
            'owner' => ['first_name' => 'Pat', 'last_name' => 'Operator', 'call_sign' => 'k1abc', 'email' => 'pat@example.org'],
            'owner_invitation_token' => str_repeat('a', 64),
            'owner_invitation_expires_at' => now()->addDays(7)->toIso8601String(),
            'system_password' => str_repeat('s', 40),
        ], $overrides)));
    };
});

afterEach(function () {
    @unlink($this->bootstrapFile);
});

test('sets up the club with the owner as System Administrator', function () {
    ($this->writeBootstrap)();

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertSuccessful();

    $owner = User::where('call_sign', 'K1ABC')->firstOrFail();
    expect($owner->hasRole('System Administrator'))->toBeTrue()
        ->and($owner->email_verified_at)->toBeNull();

    $invitation = UserInvitation::where('user_id', $owner->id)->firstOrFail();
    expect($invitation->token)->toBe(str_repeat('a', 64))
        ->and($invitation->isValid())->toBeTrue();

    $system = User::where('call_sign', User::SYSTEM_CALL_SIGN)->firstOrFail();
    expect(Hash::check(str_repeat('s', 40), $system->password))->toBeTrue()
        ->and($system->hasRole('Config Only'))->toBeTrue();

    $organization = Organization::firstOrFail();
    expect($organization->callsign)->toBe('W1AW')
        ->and((int) Setting::get('default_organization_id'))->toBe($organization->id)
        ->and(Setting::get('site_name'))->toBe('Example Amateur Radio Club')
        ->and(Setting::get('timezone'))->toBe('America/New_York')
        ->and(Setting::getBoolean('setup_completed'))->toBeTrue();
});

test('replaces the seeded SYSTEM password', function () {
    User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN, 'password' => Hash::make('ChangeMe123!')]);
    ($this->writeBootstrap)();

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertSuccessful();

    $system = User::where('call_sign', User::SYSTEM_CALL_SIGN)->sole();
    expect(Hash::check('ChangeMe123!', $system->password))->toBeFalse();
});

test('the owner can accept the invitation and sign in', function () {
    ($this->writeBootstrap)();
    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertSuccessful();

    $this->post(route('invitation.accept', str_repeat('a', 64)), [
        'password' => 'A-Strong-Password-123!',
        'password_confirmation' => 'A-Strong-Password-123!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(User::where('call_sign', 'K1ABC')->first());
});

test('does nothing once setup is complete', function () {
    Setting::set('setup_completed', 'true');
    ($this->writeBootstrap)();

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertSuccessful();

    expect(User::where('call_sign', 'K1ABC')->exists())->toBeFalse()
        ->and(Organization::count())->toBe(0);
});

test('refuses to run outside hosted mode', function () {
    config(['hosted.enabled' => false]);
    ($this->writeBootstrap)();

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertFailed();

    expect(Setting::getBoolean('setup_completed'))->toBeFalse();
});

test('rejects an invalid bootstrap file without setting anything up', function (array $overrides) {
    ($this->writeBootstrap)($overrides);

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])->assertFailed();

    expect(Setting::getBoolean('setup_completed'))->toBeFalse()
        ->and(Organization::count())->toBe(0);
})->with([
    'short SYSTEM password' => [['system_password' => 'short']],
    'short invitation token' => [['owner_invitation_token' => 'abc']],
    'expired invitation' => [['owner_invitation_expires_at' => now()->subDay()->toIso8601String()]],
    'owner named SYSTEM' => [['owner' => ['call_sign' => 'system']]],
    'bad timezone' => [['timezone' => 'Mars/Olympus']],
]);

test('fails on a missing file', function () {
    $this->artisan('tenant:bootstrap', ['--from' => '/nonexistent/tenant.json'])->assertFailed();
});

test('reports what is missing when the file is not a JSON object', function (string $contents) {
    file_put_contents($this->bootstrapFile, $contents);

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])
        ->expectsOutputToContain('required')
        ->assertFailed();

    expect(Organization::count())->toBe(0);
})->with(['null' => 'null', 'list' => '[1, 2]', 'string' => '"club"']);

test('fails on a file that is not JSON', function () {
    file_put_contents($this->bootstrapFile, '{not json');

    $this->artisan('tenant:bootstrap', ['--from' => $this->bootstrapFile])
        ->expectsOutputToContain('is not valid JSON')
        ->assertFailed();
});
