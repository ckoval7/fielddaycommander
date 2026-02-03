<?php

use App\Models\User;

beforeEach(function () {
    \Illuminate\Support\Facades\DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('last login info is updated when user logs in', function () {
    $user = User::factory()->create([
        'last_login_at' => null,
        'last_login_ip' => null,
    ]);

    expect($user->last_login_at)->toBeNull();
    expect($user->last_login_ip)->toBeNull();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull();
    expect($user->last_login_ip)->not->toBeNull();
});

test('last login time is updated on subsequent logins', function () {
    $user = User::factory()->create([
        'last_login_at' => now()->subDay(),
        'last_login_ip' => '192.168.1.1',
    ]);

    $originalLoginAt = $user->last_login_at;

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->refresh();

    expect($user->last_login_at->greaterThan($originalLoginAt))->toBeTrue();
});
