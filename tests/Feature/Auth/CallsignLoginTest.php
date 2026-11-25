<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('a user can sign in with their callsign', function () {
    $user = User::factory()->create(['call_sign' => 'W1AW']);

    $this->post('/login', ['email' => 'W1AW', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('callsign sign-in ignores case and surrounding spaces', function () {
    $user = User::factory()->create(['call_sign' => 'K2ABC']);

    $this->post('/login', ['email' => '  k2abc ', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('a user can still sign in with their email', function () {
    $user = User::factory()->create(['email' => 'op@example.com']);

    $this->post('/login', ['email' => 'op@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('a callsign with the wrong password is rejected', function () {
    User::factory()->create(['call_sign' => 'W1AW']);

    $this->post('/login', ['email' => 'W1AW', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a callsign held by two active accounts cannot be used to sign in', function () {
    User::factory()->create(['call_sign' => 'N0DUP']);
    User::factory()->create(['call_sign' => 'N0DUP']);

    $this->post('/login', ['email' => 'N0DUP', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deleted account does not block callsign sign-in for its successor', function () {
    User::factory()->create(['call_sign' => 'W3OLD'])->delete();
    $current = User::factory()->create(['call_sign' => 'W3OLD']);

    $this->post('/login', ['email' => 'W3OLD', 'password' => 'password']);

    $this->assertAuthenticatedAs($current);
});

test('failed callsign sign-ins count toward the account lockout', function () {
    $user = User::factory()->create(['call_sign' => 'W1AW']);

    $this->post('/login', ['email' => 'W1AW', 'password' => 'wrong-password']);

    expect($user->fresh()->failed_login_attempts)->toBeGreaterThan(0);
});

test('a user with two-factor enabled is sent to the challenge after a callsign sign-in', function () {
    User::factory()->create([
        'call_sign' => 'W2FA',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post('/login', ['email' => 'W2FA', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('the sign-in field accepts a callsign without a phone keyboard fighting it', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Callsign or email')
        ->assertSee('type="text"', false)
        ->assertSee('autocapitalize="none"', false)
        ->assertSee('autocomplete="username"', false)
        ->assertDontSee('type="email"', false);
});
