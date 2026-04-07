<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

describe('disabled mode', function () {
    beforeEach(function () {
        config(['auth-security.registration_mode' => 'disabled']);
    });

    test('register route returns 404 when registration is disabled', function () {
        $response = $this->get('/register');
        $response->assertStatus(404);
    });

    test('register POST returns 404 when registration is disabled', function () {
        $response = $this->post('/register', [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $response->assertStatus(404);
    });

    test('login page does not show register link when disabled', function () {
        $response = $this->get('/login');
        $response->assertDontSee('Need an account?');
    });
});

describe('open mode', function () {
    beforeEach(function () {
        config(['auth-security.registration_mode' => 'open']);
    });

    test('register route is accessible in open mode', function () {
        $response = $this->get('/register');
        $response->assertStatus(200);
    });

    test('login page shows register link in open mode', function () {
        $response = $this->get('/login');
        $response->assertSee('Need an account?');
    });

    test('user can register and is immediately authenticated in open mode', function () {
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

        $user = User::where('email', 'test@example.com')->first();
        expect($user->account_locked_at)->toBeNull();
    });
});

describe('approval_required mode', function () {
    beforeEach(function () {
        config(['auth-security.registration_mode' => 'approval_required']);
    });

    test('register route is accessible in approval_required mode', function () {
        $response = $this->get('/register');
        $response->assertStatus(200);
    });

    test('register page shows approval notice in approval_required mode', function () {
        $response = $this->get('/register');
        $response->assertSee('administrator approval');
    });

    test('user registration locks account and redirects to pending page', function () {
        $response = $this->post('/register', [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('registration.pending'));

        $user = User::where('email', 'test@example.com')->first();
        expect($user)->not->toBeNull()
            ->and($user->account_locked_at)->not->toBeNull()
            ->and($user->hasRole('Operator'))->toBeTrue();
    });

    test('locked user from approval mode cannot login', function () {
        $this->post('/register', [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertGuest();
    });

    test('registration pending page can be rendered', function () {
        $response = $this->get(route('registration.pending'));
        $response->assertStatus(200);
        $response->assertSee('pending');
    });
});

describe('email_verification_required mode', function () {
    beforeEach(function () {
        config(['auth-security.registration_mode' => 'email_verification_required']);
        config(['fortify.features' => array_values(array_filter([
            \Laravel\Fortify\Features::registration(),
            \Laravel\Fortify\Features::emailVerification(),
            \Laravel\Fortify\Features::updateProfileInformation(),
            \Laravel\Fortify\Features::updatePasswords(),
            \Laravel\Fortify\Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        ]))]);
    });

    test('register route is accessible in email_verification_required mode', function () {
        $response = $this->get('/register');
        $response->assertStatus(200);
    });

    test('user is authenticated but unverified after registration', function () {
        $response = $this->post('/register', [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->first();
        expect($user->email_verified_at)->toBeNull();
        expect($user->account_locked_at)->toBeNull();
    });

    test('unverified user is redirected to verification notice', function () {
        $this->post('/register', [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->get('/profile');
        $response->assertRedirect('/email/verify');
    });
});
