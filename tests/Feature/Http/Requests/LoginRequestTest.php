<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    \Illuminate\Support\Facades\DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

// =============================================================================
// Validation Rules
// =============================================================================

describe('LoginRequest validation', function () {
    test('requires email field', function () {
        $this->post('/login', ['password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('requires password field', function () {
        $this->post('/login', ['email' => 'test@example.com'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    });

    test('requires valid email format', function () {
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('requires both fields when submitting empty form', function () {
        $this->post('/login', [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    });
});

// =============================================================================
// Authentication
// =============================================================================

describe('LoginRequest authentication', function () {
    test('authenticates user with valid credentials', function () {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    });

    test('rejects invalid password', function () {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('rejects non-existent email', function () {
        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    test('redirects after successful login', function () {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();
    });

});

// =============================================================================
// Rate Limiting
// =============================================================================

describe('LoginRequest rate limiting', function () {
    test('allows up to 5 failed attempts before returning 429', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertRedirect();

            $this->assertGuest();
        }

        // 6th attempt should be blocked by the route middleware with 429
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->assertGuest();
    });

    test('locked out user cannot login even with correct credentials', function () {
        $user = User::factory()->create();

        // Exhaust all 5 allowed attempts
        foreach (range(1, 5) as $attempt) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // Attempt with correct credentials while rate limited
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(429);

        $this->assertGuest();
    });

    test('clears rate limiter after successful login', function () {
        $user = User::factory()->create();

        $throttleKey = \Illuminate\Support\Str::transliterate(
            \Illuminate\Support\Str::lower($user->email).'|127.0.0.1'
        );

        // Use up 4 failed attempts
        foreach (range(1, 4) as $attempt) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // Successful login clears the rate limiter
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        expect(RateLimiter::attempts($throttleKey))->toBe(0);
    });

    test('rate limit is per user email and IP', function () {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Exhaust rate limit for user A
        foreach (range(1, 5) as $attempt) {
            $this->post('/login', [
                'email' => $userA->email,
                'password' => 'wrong-password',
            ]);
        }

        // User B with different email should not be affected
        $this->post('/login', [
            'email' => $userB->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});
