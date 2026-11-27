<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Event;
use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Reads the settings in config/auth-security.php that shape sign-in,
 * passwords and sessions.
 */
class AuthSecurity
{
    /**
     * Holding any of these makes an account administrative: it gets the
     * stricter minimum password length and the shorter idle timeout.
     *
     * @var list<string>
     */
    public const array ADMIN_PERMISSIONS = ['manage-users', 'manage-roles', 'manage-settings'];

    /** @var list<string> */
    public const array RESET_METHODS = ['email', 'admin_only', 'hybrid'];

    /** @var list<string> */
    public const array FAILED_LOGIN_STRATEGIES = ['lockout', 'progressive_delay'];

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Whether an account, or a role about to be given to one, is administrative.
     */
    public static function isAdministrative(User|Role|null $subject): bool
    {
        if ($subject === null) {
            return false;
        }

        $held = $subject instanceof User
            ? $subject->getAllPermissions()->pluck('name')
            : $subject->permissions->pluck('name');

        return $held->intersect(self::ADMIN_PERMISSIONS)->isNotEmpty();
    }

    /**
     * The password rule for an account, or for a new account with the given role.
     */
    public static function passwordRule(User|Role|null $subject = null): Password
    {
        $minimum = self::isAdministrative($subject)
            ? max(self::minimumLength(), (int) config('auth-security.password_admin_min_length'))
            : self::minimumLength();

        return Password::min($minimum);
    }

    /**
     * Minimum password length for every account (PASSWORD_MIN_LENGTH, never below 8).
     */
    public static function minimumLength(): int
    {
        return max(8, (int) config('auth-security.password_min_length'));
    }

    public static function resetMethod(): string
    {
        return (string) config('auth-security.password_reset_method');
    }

    /**
     * Whether people can request their own reset link by email (not admin_only).
     */
    public static function allowsEmailResets(): bool
    {
        return self::resetMethod() !== 'admin_only';
    }

    /**
     * Whether an administrator can give someone a temporary password. Off
     * while PASSWORD_RESET_METHOD is email, unless email isn't configured,
     * since then a temporary password is the only way back in.
     */
    public static function allowsAdminPasswordSet(): bool
    {
        return self::resetMethod() !== 'email' || ! config('mail.email_configured');
    }

    /**
     * Length of a generated temporary password: 16, or the account's minimum if longer.
     */
    public static function temporaryPasswordLength(?User $user): int
    {
        return max(16, self::isAdministrative($user)
            ? max(self::minimumLength(), (int) config('auth-security.password_admin_min_length'))
            : self::minimumLength());
    }

    /**
     * Minutes of inactivity after which an account is signed out.
     */
    public static function idleTimeoutMinutes(User $user): int
    {
        return max(1, (int) config(self::isAdministrative($user)
            ? 'auth-security.session_timeout_admin'
            : 'auth-security.session_timeout_default'));
    }

    /**
     * Whether idle sign-outs are paused because an event is in progress or in
     * its setup window (EVENT_DURATION_MODE).
     */
    public static function idleTimeoutPausedForEvent(): bool
    {
        if (! filter_var(config('auth-security.event_duration_mode'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return Event::query()->active()->exists() || Event::query()->inSetupWindow()->exists();
    }

    /**
     * How long the session itself may live without a request. Idle sign-outs
     * are enforced by EnforceIdleTimeout, so the session must outlast them:
     * the longest timeout, or two days while EVENT_DURATION_MODE can keep an
     * operator signed in across a whole event.
     */
    public static function sessionLifetimeMinutes(): int
    {
        return max(
            (int) config('session.lifetime'),
            (int) config('auth-security.session_timeout_admin'),
            (int) config('auth-security.session_timeout_default'),
            filter_var(config('auth-security.event_duration_mode'), FILTER_VALIDATE_BOOL) ? 2880 : 0,
        );
    }
}
