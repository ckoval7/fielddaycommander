<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Ensure roles exist
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    // Mark setup as complete so middleware allows registration
    \Illuminate\Support\Facades\DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('new users are assigned the operator role by default', function () {
    $response = $this->post('/register', [
        'call_sign' => 'W1AW',
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertStatus(302);

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('Operator'))->toBeTrue();
});

test('registered users can login', function () {
    $this->post('/register', [
        'call_sign' => 'K2XYZ',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->post('/logout');

    $response = $this->post('/login', [
        'email' => 'jane@example.com',
        'password' => 'password123',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});
