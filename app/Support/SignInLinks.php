<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * One-time sign-in links: signed, short-lived URLs that sign someone in to
 * an account without its password or 2FA. They're for getting back into an
 * account (most often SYSTEM) from the server's command line, and for an
 * outside service holding SIGN_IN_LINK_KEY, such as a hosting provider's
 * support desk.
 *
 *     /sign-in/link?user=<callsign>&by=<who>&expires=<unix time>&nonce=<random>&signature=<hex>
 *
 * signature = HMAC-SHA256(key, "fd-commander-sign-in-link\n<host>\n<user>\n<by>\n<expires>\n<nonce>")
 *
 * Binding the host keeps a link for one install from opening another, the
 * expiry is capped by auth-security.sign_in_link_max_minutes, and each
 * nonce works once. "by" names who opened the account, for the audit log
 * and the SYSTEM banner.
 */
class SignInLinks
{
    public const string SIGNATURE_CONTEXT = 'fd-commander-sign-in-link';

    /**
     * Session key holding "by" for a session opened with a sign-in link.
     */
    public const string SESSION_KEY = 'sign_in_link.by';

    public const int MIN_NONCE_LENGTH = 16;

    public const int MAX_BY_LENGTH = 100;

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * The longest a link may stay valid, in minutes.
     */
    public static function maxMinutes(): int
    {
        return max(1, (int) config('auth-security.sign_in_link_max_minutes', 10));
    }

    /**
     * The key `user:sign-in-link` signs with, derived from APP_KEY so that
     * nothing else needs configuring.
     */
    public static function localKey(): string
    {
        return hash_hmac('sha256', self::SIGNATURE_CONTEXT.'-key', (string) config('app.key'));
    }

    /**
     * Every key a link may be signed with.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_filter([
            self::localKey(),
            (string) config('auth-security.sign_in_link_key'),
        ], filled(...)));
    }

    public static function sign(string $key, string $host, string $user, string $by, int $expires, string $nonce): string
    {
        $payload = implode("\n", [self::SIGNATURE_CONTEXT, strtolower($host), mb_strtoupper($user), $by, $expires, $nonce]);

        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * Whether the signature matches any accepted key.
     */
    public static function verify(string $signature, string $host, string $user, string $by, int $expires, string $nonce): bool
    {
        foreach (self::keys() as $key) {
            if (hash_equals(self::sign($key, $host, $user, $by, $expires, $nonce), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current session was opened with a sign-in link.
     */
    public static function openedSession(): bool
    {
        return session()->has(self::SESSION_KEY);
    }
}
