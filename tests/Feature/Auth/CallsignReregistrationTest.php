<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed roles and permissions (required for RefreshDatabase)
    $this->artisan('db:seed', ['--class' => 'PermissionSeeder']);
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    // Mark system setup as complete to bypass setup middleware
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('allows new user to register with callsign from soft-deleted user', function () {
    // Create and then soft-delete a user
    $deletedUser = User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'old@example.com',
    ]);
    $deletedUser->delete();

    // Verify the user is soft-deleted
    expect($deletedUser->trashed())->toBeTrue();

    // Attempt to register a new user with the same callsign
    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    // Should succeed - soft-deleted callsigns should be reusable
    $response->assertRedirect();

    // Verify new user was created
    $newUser = User::where('email', 'new@example.com')->first();
    expect($newUser)
        ->not->toBeNull()
        ->call_sign->toBe('W1AW');

    // Verify old user is still soft-deleted
    expect(User::withTrashed()->where('email', 'old@example.com')->first())
        ->trashed()->toBeTrue();
});

it('prevents registration with callsign from active user', function () {
    // Create an active user
    User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'existing@example.com',
    ]);

    // Attempt to register a new user with the same callsign
    $response = $this->from('/register')->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    // Should fail - callsign is already in use by active user
    $response->assertRedirect('/register')
        ->assertSessionHasErrors(['call_sign']);

    // Verify new user was NOT created
    expect(User::where('email', 'new@example.com')->first())->toBeNull();
});

it('allows email reuse from soft-deleted user', function () {
    // Create and then soft-delete a user
    $deletedUser = User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'reused@example.com',
    ]);
    $deletedUser->delete();

    // Attempt to register a new user with the same email
    $response = $this->post('/register', [
        'call_sign' => 'K1XYZ',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'reused@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    // Should succeed - soft-deleted emails should be reusable
    $response->assertRedirect();

    // Verify new user was created
    $newUser = User::where('call_sign', 'K1XYZ')->first();
    expect($newUser)
        ->not->toBeNull()
        ->email->toBe('reused@example.com');
});
