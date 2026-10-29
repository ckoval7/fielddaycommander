<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formats a start/end pair in the app's local timezone for messages,
 * such as "Oct 28, 2:00 PM to 4:00 PM EDT".
 */
class LocalTimeRange
{
    /**
     * Static-only utility class; instantiation is disallowed.
     */
    private function __construct() {}

    public static function format(CarbonInterface $start, CarbonInterface $end): string
    {
        $localStart = toLocalTime($start);
        $localEnd = toLocalTime($end);

        $endFormat = ($localEnd->isSameDay($localStart) ? '' : 'M j, ').timeFormat().' T';

        return $localStart->format('M j, '.timeFormat()).' to '.$localEnd->format($endFormat);
    }
}
