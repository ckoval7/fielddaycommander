<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Tests\Browser\Support\FieldDayScene;

/*
 * Signing in end to end in a real browser. The submit click is scoped to the
 * form because the theme toggle is also a submit button.
 */

it('signs in with a callsign typed in lowercase on a phone', function () {
    $scene = FieldDayScene::create();

    visit('/login')->on()->mobile()->resize(390, 844)
        ->type('input[name="email"]', strtolower($scene->admin->call_sign))
        ->type('input[name="password"]', 'password')
        ->click('form[method="POST"] button[type="submit"]')
        ->assertPathIsNot('/login')
        ->assertNoJavaScriptErrors();

    $this->assertAuthenticatedAs($scene->admin);
});

it('signs in with an email on a desktop', function () {
    $scene = FieldDayScene::create();

    visit('/login')->on()->desktop()->resize(1280, 800)
        ->type('input[name="email"]', $scene->admin->email)
        ->type('input[name="password"]', 'password')
        ->click('form[method="POST"] button[type="submit"]')
        ->assertPathIsNot('/login')
        ->assertNoJavaScriptErrors();

    $this->assertAuthenticatedAs($scene->admin);
});

it('shows the error under the field when the password is wrong', function () {
    $scene = FieldDayScene::create();

    visit('/login')->on()->mobile()->resize(390, 844)
        ->type('input[name="email"]', $scene->admin->call_sign)
        ->type('input[name="password"]', 'not-the-password')
        ->click('form[method="POST"] button[type="submit"]')
        ->assertPathIs('/login')
        ->assertSee('These credentials do not match our records.');

    $this->assertGuest();
});
