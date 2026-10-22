<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Section;
use Illuminate\Support\Collection;

/**
 * Decides how often generated demo QSOs come from Canada and which Canadian
 * section they land in. Canada stays a minority of the log, and the
 * Territories (TER) are weighted to show up only occasionally, the way a
 * real Field Day log rarely includes one. Yukon (YT), which Winter Field Day
 * lists on its own and ARRL folds into TER, is just as rare.
 */
class CanadianSectionPicker
{
    /**
     * Share of generated contacts, in percent, that come from Canada.
     */
    public const CANADIAN_CONTACT_PERCENT = 7;

    /**
     * Relative pick weight for any Canadian section not listed in SECTION_WEIGHTS.
     */
    private const DEFAULT_WEIGHT = 20;

    /** @var array<string, int> */
    private const SECTION_WEIGHTS = [
        'TER' => 1,
        'YT' => 1,
    ];

    public static function isCanadianContact(): bool
    {
        return random_int(1, 100) <= self::CANADIAN_CONTACT_PERCENT;
    }

    /**
     * Pick a weighted-random section from a collection of Canadian sections.
     *
     * @param  Collection<array-key, Section>  $canadianSections
     */
    public static function pick(Collection $canadianSections): Section
    {
        $sections = $canadianSections->values();
        $weights = $sections->map(fn (Section $section): int => self::SECTION_WEIGHTS[$section->code] ?? self::DEFAULT_WEIGHT);

        $roll = random_int(1, $weights->sum());

        foreach ($sections as $index => $section) {
            $roll -= $weights[$index];

            if ($roll <= 0) {
                return $section;
            }
        }

        return $sections->last();
    }
}
