<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class Version
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Parse a YY.MM.patch version string (with optional pre-release suffix).
     *
     * @return array{year: int, month: int, patch: int, pre: ?string}|null
     */
    public static function parse(string $version): ?array
    {
        $version = ltrim(trim($version), 'vV');

        if (! preg_match('/^(\d{2})\.(\d{2})\.(\d+)(-[A-Za-z0-9.\-]+)?$/', $version, $m)) {
            return null;
        }

        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return null;
        }

        return [
            'year' => (int) $m[1],
            'month' => $month,
            'patch' => (int) $m[3],
            'pre' => $m[4] ?? null,
        ];
    }

    /**
     * Return the YY.MM prefix for the given moment (UTC).
     */
    public static function currentMonth(?DateTimeInterface $now = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->format('y.m');
    }

    /**
     * Compute the next patch version for the given YY.MM, given a list of
     * existing tag strings (e.g. `git tag -l`). Tags that don't match the
     * scheme are ignored. If no matching tag exists, returns "{month}.{patchStart}".
     *
     * @param  array<int, string>  $tags
     */
    public static function nextPatch(string $month, array $tags, int $patchStart = 1): string
    {
        if (! preg_match('/^(\d{2})\.(\d{2})$/', $month, $m)) {
            throw new \InvalidArgumentException("Invalid YY.MM month: {$month}");
        }

        $monthInt = (int) $m[2];

        if ($monthInt < 1 || $monthInt > 12) {
            throw new \InvalidArgumentException("Invalid YY.MM month: {$month}");
        }

        $highest = -1;

        foreach ($tags as $tag) {
            $parsed = self::parse($tag);

            if ($parsed === null || $parsed['pre'] !== null) {
                continue;
            }

            if ($parsed['year'] === (int) $m[1] && $parsed['month'] === $monthInt) {
                $highest = max($highest, $parsed['patch']);
            }
        }

        $next = $highest < 0 ? $patchStart : $highest + 1;

        return sprintf('%s.%d', $month, $next);
    }
}
