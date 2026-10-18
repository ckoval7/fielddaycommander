<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

class LocalNetworkHosts
{
    /**
     * Anchored, case-insensitive patterns for hosts that can only be reached
     * from the local network: loopback, RFC 1918 private IPv4, CGNAT and
     * Tailscale (100.64.0.0/10), IPv6 loopback, unique local (fc00::/7) and
     * link-local (fe80::/10) literals, and mDNS ".local" names.
     *
     * None of these can name a public, attacker-controlled server, so the
     * app trusts them as Host headers and Reverb accepts them as WebSocket
     * origins. IPv6 literals carry the brackets used in Host and Origin.
     *
     * @var list<string>
     */
    public const array PATTERNS = [
        '^localhost$',
        '^127(\.\d{1,3}){3}$',
        '^10(\.\d{1,3}){3}$',
        '^172\.(1[6-9]|2\d|3[01])(\.\d{1,3}){2}$',
        '^192\.168(\.\d{1,3}){2}$',
        '^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])(\.\d{1,3}){2}$',
        '^\[::1\]$',
        '^\[f[cd][0-9a-f]{0,2}:[0-9a-f:.]*\]$',
        '^\[fe[89ab][0-9a-f]?:[0-9a-f:.]*\]$',
        '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+local$',
    ];

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Whether the host (as found in a Host or Origin header, without a port)
     * belongs to the local network.
     */
    public static function contains(?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match('{'.$pattern.'}i', $host) === 1) {
                return true;
            }
        }

        return false;
    }
}
