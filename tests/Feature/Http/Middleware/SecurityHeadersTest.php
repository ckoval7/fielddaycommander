<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('web responses carry baseline security headers', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('redirects also carry security headers', function () {
    $this->get(route('dashboard.alt'))
        ->assertRedirect()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('hsts is off by default, even over https', function () {
    $this->get(str_replace('http://', 'https://', route('login')))
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts is sent over https when a max age is configured', function () {
    config(['auth-security.hsts_max_age' => 300]);

    $this->get(str_replace('http://', 'https://', route('login')))
        ->assertHeader('Strict-Transport-Security', 'max-age=300');
});

test('hsts is never sent over plain http', function () {
    config(['auth-security.hsts_max_age' => 300]);

    $this->get(route('login'))
        ->assertHeaderMissing('Strict-Transport-Security');
});
