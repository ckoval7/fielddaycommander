<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('call_sign is normalized to uppercase when creating a user', function () {
    $user = User::factory()->create([
        'call_sign' => 'w1aw',
    ]);

    expect($user->call_sign)->toBe('W1AW');
});

test('call_sign is normalized to uppercase when updating a user', function () {
    $user = User::factory()->create([
        'call_sign' => 'W1AW',
    ]);

    $user->update(['call_sign' => 'k6abc']);

    expect($user->fresh()->call_sign)->toBe('K6ABC');
});

test('call_sign handles mixed case input', function () {
    $user = User::factory()->create([
        'call_sign' => 'W1aW',
    ]);

    expect($user->call_sign)->toBe('W1AW');
});

test('call_sign handles null values', function () {
    $user = User::factory()->make();
    $user->call_sign = null;

    expect($user->call_sign)->toBeNull();
});

test('effectiveCallSign returns uppercase callsign', function () {
    $user = User::factory()->create([
        'call_sign' => 'w1aw',
    ]);

    expect($user->effectiveCallSign())->toBe('W1AW');
});
