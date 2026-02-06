<?php

use App\Models\User;

test('getInitials returns first letter of first name and last name', function () {
    $user = User::factory()->make([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('JD');
});

test('getInitials handles single character names', function () {
    $user = User::factory()->make([
        'first_name' => 'J',
        'last_name' => 'D',
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('JD');
});

test('getInitials uppercases lowercase names', function () {
    $user = User::factory()->make([
        'first_name' => 'john',
        'last_name' => 'doe',
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('JD');
});

test('getInitials falls back to callsign when first name is missing', function () {
    $user = User::factory()->make([
        'first_name' => null,
        'last_name' => 'Doe',
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('D');
});

test('getInitials falls back to callsign when last name is missing', function () {
    $user = User::factory()->make([
        'first_name' => 'John',
        'last_name' => null,
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('J');
});

test('getInitials falls back to callsign when both names are missing', function () {
    $user = User::factory()->make([
        'first_name' => null,
        'last_name' => null,
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('W1');
});

test('getInitials handles unicode characters correctly', function () {
    $user = User::factory()->make([
        'first_name' => 'José',
        'last_name' => 'García',
        'call_sign' => 'W1AW',
    ]);

    expect($user->getInitials())->toBe('JG');
});
