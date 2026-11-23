<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The demo_session cookie that ties a visitor to their sandbox.
 *
 * It holds "sandbox UUID|user ID" for the account the visitor signs in as.
 * The cookie is encrypted, so nobody in a shared sandbox can swap in
 * someone else's user ID.
 */
class DemoCookie
{
    public const NAME = 'demo_session';

    /**
     * Remembers which sandbox a visitor just lost, so the landing page can
     * say why and offer to start again the same way.
     */
    public const ENDED_NAME = 'demo_ended';

    private const ENDED_MINUTES = 24 * 60;

    /**
     * Roles a visitor can pick, keyed by the slug the demo forms submit.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'system_admin' => 'System Administrator',
        'event_manager' => 'Event Manager',
        'station_captain' => 'Station Captain',
        'operator' => 'Operator',
    ];

    private function __construct(
        public readonly string $uuid,
        public readonly int $userId,
    ) {}

    /**
     * The visitor's demo cookie, or null when it is missing or malformed.
     */
    public static function fromRequest(Request $request): ?self
    {
        return self::parse($request->cookie(self::NAME));
    }

    /**
     * The sandbox the visitor was last sent away from, if any.
     */
    public static function endedFromRequest(Request $request): ?self
    {
        return self::parse($request->cookie(self::ENDED_NAME));
    }

    public static function makeEnded(self $visitor, bool $secure): Cookie
    {
        return cookie(self::ENDED_NAME, $visitor->uuid.'|'.$visitor->userId, self::ENDED_MINUTES, '/', null, $secure, true, false, 'Lax');
    }

    private static function parse(mixed $value): ?self
    {
        [$uuid, $userId] = array_pad(explode('|', (string) $value, 2), 2, null);

        if (! Str::isUuid($uuid) || ! ctype_digit((string) $userId)) {
            return null;
        }

        return new self($uuid, (int) $userId);
    }

    public static function make(string $uuid, int $userId, int $minutes, bool $secure): Cookie
    {
        return cookie(self::NAME, $uuid.'|'.$userId, $minutes, '/', null, $secure, true, false, 'Lax');
    }

    /**
     * The Spatie role for a persona slug, falling back to the least privileged one.
     */
    public static function roleName(?string $slug): string
    {
        return self::ROLES[$slug] ?? 'Operator';
    }
}
