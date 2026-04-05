<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure Operator role exists (required for new user registration)
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    // Mark system as set up for registration tests
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register with call sign and names', function () {
    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));

    // Verify user was created with correct fields
    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->call_sign)->toBe('W1AW')
        ->and($user->first_name)->toBe('John')
        ->and($user->last_name)->toBe('Doe');
});

test('registration requires all fields', function () {
    $response = $this->post('/register', []);

    $response->assertSessionHasErrors(['call_sign', 'first_name', 'last_name', 'email', 'password']);
    $this->assertGuest();
});

test('registration requires valid email', function () {
    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'not-an-email',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('registration requires password confirmation', function () {
    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different-password',
    ]);

    $response->assertSessionHasErrors(['password']);
    $this->assertGuest();
});

test('registration prevents duplicate email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('registration persists cpr_aed_trained flag', function () {
    $response = $this->post('/register', [
        'call_sign' => 'KD2CPR',
        'first_name' => 'Medic',
        'last_name' => 'Doe',
        'email' => 'medic@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'is_cpr_aed_trained' => '1',
    ]);

    $this->assertAuthenticated();

    $user = User::where('call_sign', 'KD2CPR')->first();
    expect($user)->not->toBeNull()
        ->and($user->is_cpr_aed_trained)->toBeTrue();
});

test('registration defaults cpr_aed_trained to false', function () {
    $response = $this->post('/register', [
        'call_sign' => 'KD2NUL',
        'first_name' => 'Normal',
        'last_name' => 'User',
        'email' => 'normal@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();

    $user = User::where('call_sign', 'KD2NUL')->first();
    expect($user)->not->toBeNull()
        ->and($user->is_cpr_aed_trained)->toBeFalse();
});

test('registration prevents duplicate call sign', function () {
    User::factory()->create(['call_sign' => 'W1AW']);

    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['call_sign']);
    $this->assertGuest();
});
