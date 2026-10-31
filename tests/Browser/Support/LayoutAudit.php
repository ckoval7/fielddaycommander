<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Browser\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs layout-audit.js in a browser page and fails with every problem it finds.
 */
final class LayoutAudit
{
    /**
     * @param  object  $page  A Pest browser page, as returned by visit().
     * @param  list<string>  $skip  Checks to skip on this page, by key (overflow, offscreen, overlapping, covered, spilling, smallTargets, smallFonts, gutter).
     * @return array{url: string, width: int, coarse: bool, problems: array<string, list<string>>}
     */
    public static function assertClean(object $page, array $skip = []): array
    {
        /** @var array{url: string, width: int, coarse: bool, problems: array<string, list<string>>} $result */
        $result = $page->script((string) file_get_contents(__DIR__.'/layout-audit.js'));

        $lines = [];
        foreach ($result['problems'] as $check => $found) {
            if (in_array($check, $skip, true)) {
                continue;
            }
            foreach (array_slice($found, 0, 8) as $problem) {
                $lines[] = "  [{$check}] {$problem}";
            }
            if (count($found) > 8) {
                $lines[] = "  [{$check}] ...and ".(count($found) - 8).' more';
            }
        }

        Assert::assertSame(
            [],
            $lines,
            sprintf(
                "Layout problems on %s at %dpx (%s):\n%s",
                $result['url'],
                $result['width'],
                $result['coarse'] ? 'touch' : 'mouse',
                implode("\n", $lines),
            ),
        );

        return $result;
    }
}
