<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure roles exist
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    // Mark setup as complete so middleware allows registration
    \Illuminate\Support\Facades\DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );

    config(['auth-security.registration_mode' => 'open']);
});

test('registration normalizes callsign to uppercase', function () {
    $response = $this->post('/register', [
        'call_sign' => 'w1aw',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'john@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->call_sign)->toBe('W1AW');
});

test('registration handles mixed case callsign', function () {
    $response = $this->post('/register', [
        'call_sign' => 'K6AbC',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->call_sign)->toBe('K6ABC');
});

test('duplicate callsign validation works regardless of case', function () {
    // Create a user with uppercase callsign
    User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'existing@example.com',
    ]);

    // Try to register with same callsign in lowercase
    $response = $this->post('/register', [
        'call_sign' => 'w1aw',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'new@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertSessionHasErrors(['call_sign']);
});
