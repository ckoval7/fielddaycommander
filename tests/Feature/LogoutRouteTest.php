<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('GET /logout shows a sign out button without signing the user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/logout')
        ->assertOk()
        ->assertSee('Sign Out')
        ->assertSee('action="'.route('logout').'"', false);

    $this->assertAuthenticatedAs($user);
});

test('GET /logout sends guests to the login page', function () {
    $this->get('/logout')->assertRedirect(route('login'));
});

test('POST /logout still signs the user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect();

    $this->assertGuest();
});

test('GET /logout is reachable in station mode', function () {
    $ghost = User::factory()->create(['is_ghost' => true, 'account_locked_at' => now()]);
    $device = StationDevice::factory()->withToken('device-token')->create();

    $this->actingAs($ghost)
        ->withCookie(StationMode::COOKIE, 'device-token')
        ->withSession([StationMode::SESSION_KEY => $device->id])
        ->get('/logout')
        ->assertOk()
        ->assertSee('Sign Out');

    $this->assertAuthenticatedAs($ghost);
});

test('GET /logout is reachable while a password change is required', function () {
    $user = User::factory()->create(['requires_password_change' => true]);

    $this->actingAs($user)
        ->get('/logout')
        ->assertOk()
        ->assertSee('Sign Out');
});
