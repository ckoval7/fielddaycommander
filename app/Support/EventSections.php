<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Event;

/**
 * Which sections an event recognises on its own.
 *
 * Winter Field Day recognises sections that ARRL events do not. For any other
 * event those codes fold into the section ARRL counts them under, so section
 * lists, worked counts and the map agree with how the event is scored.
 */
class EventSections
{
    /**
     * WFD-only section codes, keyed to the section ARRL events count them as.
     *
     * @var array<string, string>
     */
    public const WFD_ONLY = [
        'MX' => 'DX',
        'YT' => 'TER',
    ];

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Whether the event recognises the WFD-only sections.
     */
    public static function recognisesWfdSections(?Event $event): bool
    {
        return $event?->eventType?->code === 'WFD';
    }

    /**
     * Section codes the event does not list as sections of their own.
     *
     * @return list<string>
     */
    public static function foldedCodes(?Event $event): array
    {
        return self::recognisesWfdSections($event) ? [] : array_keys(self::WFD_ONLY);
    }

    /**
     * The section a logged section code counts as for the event.
     */
    public static function countedCode(string $code, ?Event $event): string
    {
        if (self::recognisesWfdSections($event)) {
            return $code;
        }

        return self::WFD_ONLY[$code] ?? $code;
    }
}
