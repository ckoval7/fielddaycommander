<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    Setting::set('setup_completed', 'true');
});

test('successful login logs user.login.success', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $auditLog = AuditLog::where('action', 'user.login.success')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeFalse();
    expect($auditLog->user_id)->toBe($user->id);
});

test('failed login logs user.login.failed with email', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrongpassword',
    ]);

    $auditLog = AuditLog::where('action', 'user.login.failed')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeTrue();
    expect($auditLog->new_values['email'])->toBe($user->email);
});

test('logout logs user.logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/logout');

    $auditLog = AuditLog::where('action', 'user.logout')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeFalse();
    expect($auditLog->user_id)->toBe($user->id);
});

test('GET /logout does not end the session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/logout');

    expect(auth()->check())->toBeTrue()
        ->and(AuditLog::where('action', 'user.logout')->exists())->toBeFalse();
});
