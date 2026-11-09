<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\DTOs;

use DateTimeImmutable;

/**
 * A relay's place in the contact feed: the last (updated_at, id) it was given,
 * and, partway through a run of pages, where to go back to when the run ends.
 * Relays treat the encoded form as opaque.
 */
readonly class RelayContactCursor
{
    public const TIME_FORMAT = 'Y-m-d H:i:s';

    /** Before any contact, and a valid TIMESTAMP in every time zone. */
    private const START_TIME = '1970-01-02 00:00:00';

    private const OLDEST_YEAR = 1970;

    private const PATTERN = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,18})(?:\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}))?$/';

    public function __construct(
        public string $updatedAt,
        public int $id,
        public ?string $rewindTo = null,
    ) {}

    public static function start(): self
    {
        return new self(self::START_TIME, 0);
    }

    /**
     * The cursor a relay sent, or null when it isn't one this server issued.
     * Times are checked here because MySQL refuses to compare a TIMESTAMP
     * with an impossible date, which would otherwise be a server error.
     */
    public static function decode(string $encoded): ?self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false || preg_match(self::PATTERN, $decoded, $parts) !== 1) {
            return null;
        }

        $rewindTo = $parts[3] ?? null;

        if (! self::isValidTime($parts[1]) || ($rewindTo !== null && ! self::isValidTime($rewindTo))) {
            return null;
        }

        return new self($parts[1], (int) $parts[2], $rewindTo);
    }

    public function encode(): string
    {
        $plain = $this->updatedAt.'|'.$this->id.($this->rewindTo !== null ? '|'.$this->rewindTo : '');

        return rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');
    }

    /**
     * Whichever of the two positions comes first in the feed.
     */
    public function earlierOf(self $other): self
    {
        $isEarlier = $this->updatedAt === $other->updatedAt
            ? $this->id <= $other->id
            : $this->updatedAt < $other->updatedAt;

        return new self(
            $isEarlier ? $this->updatedAt : $other->updatedAt,
            $isEarlier ? $this->id : $other->id,
        );
    }

    private static function isValidTime(string $time): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $time);

        return $parsed !== false
            && $parsed->format(self::TIME_FORMAT) === $time
            && (int) $parsed->format('Y') >= self::OLDEST_YEAR;
    }
}
