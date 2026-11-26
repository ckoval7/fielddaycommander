<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Http\Controllers\Auth\SupportSignInController;
use App\Livewire\Components\SystemAccountBanner;
use App\Livewire\Users\UserManagement;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\HostedMode;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true', 'updated_at' => now()]);

    config([
        'hosted.enabled' => true,
        'hosted.support_key' => 'test-support-key',
        'hosted.support_link_max_minutes' => 10,
        'auth-security.audit_logging_enabled' => true,
    ]);

    $this->system = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN, 'email' => 'admin@localhost']);
    $this->system->assignRole('Config Only');

    $this->owner = User::factory()->create(['call_sign' => 'K1ABC']);
    $this->owner->assignRole('System Administrator');

    $this->supportLink = function (array $overrides = []): string {
        $params = array_replace([
            'expires' => now()->addMinutes(5)->getTimestamp(),
            'nonce' => str_repeat('n', 32),
            'staff' => 'Support Tech',
        ], $overrides);

        $params['signature'] ??= SupportSignInController::sign('localhost', $params['expires'], $params['nonce'], $params['staff']);

        return route('support.sign-in', $params);
    };
});

test('SYSTEM cannot sign in with a password on a hosted club', function (string $login) {
    $this->post('/login', ['email' => $login, 'password' => 'password']);

    $this->assertGuest();
})->with(['callsign' => 'SYSTEM', 'email' => 'admin@localhost']);

test('SYSTEM can still sign in with a password when self-hosted', function () {
    config(['hosted.enabled' => false]);

    $this->post('/login', ['email' => 'SYSTEM', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->system);
});

test('the club cannot edit or manage SYSTEM', function () {
    expect(Gate::forUser($this->owner)->allows('update', $this->system))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('manageAccount', $this->system))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('update', User::factory()->create()))->toBeTrue();
});

test('the user list leaves SYSTEM out on a hosted club', function () {
    $this->actingAs($this->owner);

    $ids = Livewire::test(UserManagement::class)->instance()->users()->pluck('id');

    expect($ids)->toContain($this->owner->id)->not->toContain($this->system->id);
});

test('the user list still shows SYSTEM when self-hosted', function () {
    config(['hosted.enabled' => false]);
    $this->actingAs($this->owner);

    $ids = Livewire::test(UserManagement::class)->instance()->users()->pluck('id');

    expect($ids)->toContain($this->system->id);
});

test('a valid support link signs in as SYSTEM and is audited', function () {
    $this->get(($this->supportLink)())->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->system);
    expect(session(HostedMode::SUPPORT_SESSION_KEY))->toBe('Support Tech');

    $log = AuditLog::where('action', 'support.sign_in')->sole();
    expect($log->user_id)->toBe($this->system->id)
        ->and($log->new_values)->toBe(['staff' => 'Support Tech'])
        ->and($log->is_critical)->toBeTrue();
});

test('a support link works only once', function () {
    $link = ($this->supportLink)();

    $this->get($link)->assertRedirect();
    auth()->logout();

    $this->get($link)->assertForbidden();
    $this->assertGuest();
});

test('a support link is refused when tampered with or out of date', function (array $overrides) {
    $this->get(($this->supportLink)($overrides))->assertForbidden();

    $this->assertGuest();
})->with([
    'expired' => [['expires' => now()->subMinute()->getTimestamp()]],
    'expiry too far away' => [['expires' => now()->addHour()->getTimestamp()]],
    'wrong signature' => [['signature' => str_repeat('0', 64)]],
    'short nonce' => [['nonce' => 'abc']],
    'no staff name' => [['staff' => '']],
]);

test('a support link signed for another club is refused', function () {
    $expires = now()->addMinutes(5)->getTimestamp();
    $signature = SupportSignInController::sign('other-club.example.org', $expires, str_repeat('n', 32), 'Support Tech');

    $this->get(($this->supportLink)(['expires' => $expires, 'signature' => $signature]))->assertForbidden();
});

test('a staff name change after signing is refused', function () {
    $expires = now()->addMinutes(5)->getTimestamp();
    $signature = SupportSignInController::sign('localhost', $expires, str_repeat('n', 32), 'Support Tech');

    $this->get(($this->supportLink)(['expires' => $expires, 'staff' => 'Someone Else', 'signature' => $signature]))->assertForbidden();
});

test('support sign-in does not exist without hosted mode or a key', function (array $config) {
    config($config);

    $this->get(($this->supportLink)())->assertNotFound();
    $this->assertGuest();
})->with([
    'self-hosted' => [['hosted.enabled' => false]],
    'no key' => [['hosted.support_key' => null]],
]);

test('a support session is not sent to 2FA setup when 2FA is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->get(($this->supportLink)());

    $this->get(route('dashboard'))->assertOk();
});

test('the banner marks a support session', function () {
    $this->actingAs($this->system);

    Livewire::test(SystemAccountBanner::class)
        ->assertSet('isSupportSession', true)
        ->assertSee('Support session');
});

test('the banner asks a self-hoster to make a personal account', function () {
    config(['hosted.enabled' => false]);
    $this->actingAs($this->system);

    Livewire::test(SystemAccountBanner::class)
        ->assertSet('isSupportSession', false)
        ->assertSee('create a personal account');
});
