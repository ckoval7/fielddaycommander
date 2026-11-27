<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Components\SystemAccountBanner;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SignInLinks;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true', 'updated_at' => now()]);

    config([
        'app.url' => 'http://localhost',
        'auth-security.sign_in_link_key' => 'outside-service-key',
        'auth-security.sign_in_link_max_minutes' => 10,
        'auth-security.audit_logging_enabled' => true,
    ]);

    $this->system = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN, 'email' => 'admin@localhost']);
    $this->system->assignRole('Config Only');

    $this->link = function (array $overrides = [], ?string $key = 'outside-service-key'): string {
        $params = array_replace([
            'user' => 'SYSTEM',
            'by' => 'Support Tech',
            'expires' => now()->addMinutes(5)->getTimestamp(),
            'nonce' => str_repeat('n', 32),
        ], $overrides);

        $params['signature'] ??= SignInLinks::sign($key, 'localhost', $params['user'], $params['by'], $params['expires'], $params['nonce']);

        return route('sign-in-link', $params);
    };

    $this->commandLink = function (array $arguments = []): string {
        Artisan::call('user:sign-in-link', $arguments);
        preg_match('#http\S+#', Artisan::output(), $match);

        return $match[0];
    };
});

test('a link from the command line signs in as SYSTEM and is audited', function () {
    $this->get(($this->commandLink)())->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->system);
    expect(session(SignInLinks::SESSION_KEY))->toBe('the server command line');

    $log = AuditLog::where('action', 'user.login.sign_in_link')->sole();
    expect($log->user_id)->toBe($this->system->id)
        ->and($log->new_values)->toBe(['by' => 'the server command line'])
        ->and($log->is_critical)->toBeTrue();
});

test('the command line can sign in to another account', function () {
    $operator = User::factory()->create(['call_sign' => 'K1ABC']);

    $this->get(($this->commandLink)(['callsign' => 'k1abc', '--by' => 'Pat']))->assertRedirect();

    $this->assertAuthenticatedAs($operator);
});

test('the command refuses an unknown account', function () {
    $this->artisan('user:sign-in-link', ['callsign' => 'N0BODY'])->assertFailed();
});

test('the command caps how long a link lasts', function () {
    $link = ($this->commandLink)(['--minutes' => 600]);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBeLessThanOrEqual(now()->addMinutes(10)->getTimestamp());
});

test('the command signs for the address given with --url', function () {
    $link = ($this->commandLink)(['--url' => 'http://192.168.1.20:8000']);

    expect($link)->toStartWith('http://192.168.1.20:8000/sign-in/link?');

    // The same link opened by another address is refused.
    $this->get('http://localhost/sign-in/link?'.parse_url($link, PHP_URL_QUERY))->assertForbidden();
    $this->get($link)->assertRedirect(route('dashboard'));
});

test('a link signed with the outside service key works', function () {
    $this->get(($this->link)())->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->system);
});

test('an outside service key is not needed for command line links', function () {
    config(['auth-security.sign_in_link_key' => null]);

    $this->get(($this->link)())->assertForbidden();
    $this->get(($this->commandLink)())->assertRedirect();
});

test('a link works only once', function () {
    $link = ($this->link)();

    $this->get($link)->assertRedirect();
    auth()->logout();

    $this->get($link)->assertForbidden();
    $this->assertGuest();
});

test('a link is refused when tampered with or out of date', function (array $overrides) {
    $this->get(($this->link)($overrides))->assertForbidden();

    $this->assertGuest();
})->with([
    'expired' => [['expires' => now()->subMinute()->getTimestamp()]],
    'expiry too far away' => [['expires' => now()->addHour()->getTimestamp()]],
    'wrong signature' => [['signature' => str_repeat('0', 64)]],
    'short nonce' => [['nonce' => 'abc']],
    'no name' => [['by' => '']],
    'unknown account' => [['user' => 'N0BODY']],
]);

test('a link signed for another host is refused', function () {
    $expires = now()->addMinutes(5)->getTimestamp();
    $signature = SignInLinks::sign('outside-service-key', 'other-club.example.org', 'SYSTEM', 'Support Tech', $expires, str_repeat('n', 32));

    $this->get(($this->link)(['expires' => $expires, 'signature' => $signature]))->assertForbidden();
});

test('changing the account or name after signing is refused', function (array $change) {
    $expires = now()->addMinutes(5)->getTimestamp();
    User::factory()->create(['call_sign' => 'K1ABC']);
    $signature = SignInLinks::sign('outside-service-key', 'localhost', 'SYSTEM', 'Support Tech', $expires, str_repeat('n', 32));

    $this->get(($this->link)([...$change, 'expires' => $expires, 'signature' => $signature]))->assertForbidden();
})->with([
    'account' => [['user' => 'K1ABC']],
    'name' => [['by' => 'Someone Else']],
]);

test('a link does not open an account an administrator locked', function () {
    User::factory()->create(['call_sign' => 'K1ABC', 'account_locked_at' => now()]);

    $this->get(($this->link)(['user' => 'K1ABC']))->assertForbidden();
    $this->assertGuest();
});

test('a link session is not sent to 2FA setup when 2FA is required', function () {
    config(['auth-security.2fa_mode' => 'required']);

    $this->get(($this->link)());

    $this->get(route('dashboard'))->assertOk();
});

test('the banner names who opened SYSTEM with a link', function () {
    $this->get(($this->link)());

    Livewire::test(SystemAccountBanner::class)
        ->assertSet('openedBy', 'Support Tech')
        ->assertSee('Support Tech');
});

test('the banner asks for a personal account after a password sign-in', function () {
    $this->actingAs($this->system);

    Livewire::test(SystemAccountBanner::class)
        ->assertSet('openedBy', null)
        ->assertSee('create a personal account');
});
