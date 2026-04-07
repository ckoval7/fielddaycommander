<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Mark system setup as complete for these tests
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    config(['auth-security.registration_mode' => 'open']);
});

it('displays validation errors for empty registration fields', function () {
    $response = $this->from(route('register'))
        ->post(route('register'), []);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors([
        'call_sign',
        'first_name',
        'last_name',
        'email',
        'password',
    ]);
});

it('displays validation error for duplicate call sign', function () {
    User::factory()->create(['call_sign' => 'W1AW']);

    $response = $this->post(route('register'), [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['call_sign']);
});

it('displays validation error for duplicate email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->post(route('register'), [
        'call_sign' => 'K2ABC',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
});

it('displays validation error for password confirmation mismatch', function () {
    $response = $this->post(route('register'), [
        'call_sign' => 'N3XYZ',
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different-password',
    ]);

    $response->assertSessionHasErrors(['password']);
});

it('displays validation errors in the registration form view', function () {
    // Submit invalid data to trigger validation errors
    $this->from(route('register'))
        ->post(route('register'), [
            'call_sign' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors(['call_sign', 'first_name', 'last_name', 'email', 'password']);

    // Now visit the registration page which should display the errors
    $response = $this->get(route('register'));

    // Verify the view contains error messages for each field
    $response->assertSee('call sign field is required', false);
    $response->assertSee('first name field is required', false);
    $response->assertSee('last name field is required', false);
    $response->assertSee('email field must be a valid email', false);
});
