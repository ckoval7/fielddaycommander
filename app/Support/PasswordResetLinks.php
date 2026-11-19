<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

class PasswordResetLinks
{
    /**
     * Shown on the forgot-password page, and returned to a request for a link,
     * while APP_URL is unset.
     */
    public const UNAVAILABLE_MESSAGE = "Password reset by email isn't set up on this server yet. Ask an administrator to reset your password.";

    /**
     * Shown instead while PASSWORD_RESET_METHOD is admin_only.
     */
    public const ADMIN_ONLY_MESSAGE = 'Ask an administrator to reset your password.';

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * The root URL that reset links are built from, or null while APP_URL is
     * unset (localhost or a loopback address).
     *
     * Reset links never use the request's Host header. Any LAN IP and .local
     * name is a trusted host (see LocalNetworkHosts), so anyone on the network
     * could request a reset for someone else with their own machine as the
     * Host and receive the token when the victim clicks the emailed link.
     */
    public static function canonicalRoot(): ?string
    {
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || self::isLoopback($host)) {
            return null;
        }

        return $appUrl;
    }

    /**
     * Whether anyone may request a reset link for an email address. Off while
     * PASSWORD_RESET_METHOD is admin_only, and until APP_URL names the server,
     * since a link to localhost is useless in an email. Administrators can
     * still send reset emails from User Management; while APP_URL is unset
     * those links use the administrator's own address, like invitations.
     */
    public static function selfServiceAvailable(): bool
    {
        return AuthSecurity::allowsEmailResets() && self::canonicalRoot() !== null;
    }

    /**
     * Why self-service reset is off, for the forgot-password page.
     */
    public static function unavailableMessage(): string
    {
        return AuthSecurity::allowsEmailResets() ? self::UNAVAILABLE_MESSAGE : self::ADMIN_ONLY_MESSAGE;
    }

    /**
     * Build the link for a reset email.
     */
    public static function url(string $token, string $email): string
    {
        $path = route('password.reset', ['token' => $token, 'email' => $email], absolute: false);
        $root = self::canonicalRoot();

        return $root === null ? url($path) : $root.$path;
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '::1' || preg_match('/^127(\.\d{1,3}){3}$/', $host) === 1;
    }
}
