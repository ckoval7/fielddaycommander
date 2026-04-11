<?php

use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    Setting::set('setup_completed', 'true');
});

test('user with requires_password_change is redirected to profile security tab', function () {
    $user = User::factory()->create([
        'requires_password_change' => true,
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('profile', ['tab' => 'security']));
});

test('user without requires_password_change can access app normally', function () {
    $user = User::factory()->create([
        'requires_password_change' => false,
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful();
});

test('user with requires_password_change can access profile page', function () {
    $user = User::factory()->create([
        'requires_password_change' => true,
    ]);

    $this->actingAs($user)
        ->get(route('profile'))
        ->assertSuccessful();
});

test('user with requires_password_change can logout', function () {
    $user = User::factory()->create([
        'requires_password_change' => true,
    ]);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect();
});

test('guest is not affected by middleware', function () {
    $this->get('/')
        ->assertOk();
});
