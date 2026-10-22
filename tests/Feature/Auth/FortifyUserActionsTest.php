<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );
});

describe('UpdateUserPassword', function () {
    test('updates the password and clears the forced-change flag', function () {
        $user = User::factory()->create(['requires_password_change' => true]);
        $this->actingAs($user);

        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $user->refresh();
        expect(Hash::check('new-secure-password', $user->password))->toBeTrue();
        expect($user->requires_password_change)->toBeFalse();
        expect($user->password_changed_at)->not->toBeNull();
    });

    test('rejects an incorrect current password', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        expect(fn () => app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]))->toThrow(ValidationException::class);

        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    });

    test('rejects a mismatched confirmation', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        expect(fn () => app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'something-else',
        ]))->toThrow(ValidationException::class);
    });

    test('PUT /user/password updates the password for the authenticated user', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertSessionHasNoErrors();

        expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
    });

    test('PUT /user/password requires authentication', function () {
        $this->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('login'));
    });
});

describe('UpdateUserProfileInformation', function () {
    test('updates profile fields without touching verification when email is unchanged', function () {
        $user = User::factory()->create();

        app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => 'Hiram',
            'last_name' => 'Maxim',
            'email' => $user->email,
            'license_class' => 'Extra',
            'preferred_timezone' => 'America/New_York',
            'is_youth' => true,
            'is_cpr_aed_trained' => true,
        ]);

        $user->refresh();
        expect($user->first_name)->toBe('Hiram');
        expect($user->last_name)->toBe('Maxim');
        expect($user->license_class)->toBe('Extra');
        expect($user->preferred_timezone)->toBe('America/New_York');
        expect($user->is_youth)->toBeTrue();
        expect($user->is_cpr_aed_trained)->toBeTrue();
        expect($user->email_verified_at)->not->toBeNull();
    });

    test('changing email resets verification and sends a verification notice when verification is required', function () {
        config(['auth-security.registration_mode' => 'email_verification_required']);
        Notification::fake();
        $user = User::factory()->create();

        app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'new-address@example.com',
        ]);

        $user->refresh();
        expect($user->email)->toBe('new-address@example.com');
        expect($user->email_verified_at)->toBeNull();
        Notification::assertSentTo($user, VerifyEmail::class);
    });

    test('changing email in open registration mode sends no notice and keeps the user verified', function () {
        config(['auth-security.registration_mode' => 'open']);
        Notification::fake();
        $user = User::factory()->create();

        app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'new-address@example.com',
        ]);

        Notification::assertNothingSent();
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    test('rejects an email already used by another user', function () {
        $existing = User::factory()->create();
        $user = User::factory()->create();

        expect(fn () => app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $existing->email,
        ]))->toThrow(ValidationException::class);
    });

    test('allows reusing the email of a soft-deleted user', function () {
        $deleted = User::factory()->create();
        $deleted->delete();
        $user = User::factory()->create();

        app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $deleted->email,
        ]);

        expect($user->fresh()->email)->toBe($deleted->email);
    });

    test('rejects an invalid license class', function () {
        $user = User::factory()->create();

        expect(fn () => app(UpdateUserProfileInformation::class)->update($user, [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'license_class' => 'Novice',
        ]))->toThrow(ValidationException::class);
    });

    test('PUT /user/profile-information updates the authenticated user only', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create(['first_name' => 'Untouched']);

        $this->actingAs($user)
            ->put(route('user-profile-information.update'), [
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        expect($user->fresh()->first_name)->toBe('Updated');
        expect($otherUser->fresh()->first_name)->toBe('Untouched');
    });

    test('PUT /user/profile-information requires authentication', function () {
        $this->put(route('user-profile-information.update'), [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'guest@example.com',
        ])->assertRedirect(route('login'));
    });
});

describe('ResetUserPassword', function () {
    test('resets the password and clears the forced-change flag', function () {
        $user = User::factory()->create(['requires_password_change' => true]);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $user->refresh();
        expect(Hash::check('brand-new-password', $user->password))->toBeTrue();
        expect($user->requires_password_change)->toBeFalse();
        expect($user->password_changed_at)->not->toBeNull();
    });

    test('rejects a password that is too short or unconfirmed', function (array $input) {
        $user = User::factory()->create();

        expect(fn () => app(ResetUserPassword::class)->reset($user, $input))
            ->toThrow(ValidationException::class);

        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    })->with([
        'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
        'unconfirmed' => [['password' => 'brand-new-password', 'password_confirmation' => 'different-password']],
        'missing' => [[]],
    ]);
});
