<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Event;
use App\Models\Section;

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
        return self::foldedCodesForType($event?->eventType?->code);
    }

    /**
     * Section codes an event of the given type code does not list as
     * sections of their own, for forms where the event isn't saved yet.
     *
     * @return list<string>
     */
    public static function foldedCodesForType(?string $eventTypeCode): array
    {
        return $eventTypeCode === 'WFD' ? [] : array_keys(self::WFD_ONLY);
    }

    /**
     * The section a logged section code counts as for the event.
     */
    public static function countedCode(string $code, ?Event $event): string
    {
        return self::countedCodeForType($code, $event?->eventType?->code);
    }

    /**
     * The section a logged section code counts as for an event of the given
     * type code, for callers that know the type but not the event.
     */
    public static function countedCodeForType(string $code, ?string $eventTypeCode): string
    {
        if ($eventTypeCode === 'WFD') {
            return $code;
        }

        return self::WFD_ONLY[strtoupper($code)] ?? $code;
    }

    /**
     * Point each folded code in a code-to-id section map at the id of the
     * section it counts as, so a lookup by the logged code stores the
     * counted section.
     *
     * @param  array<string, int>  $idsByCode
     * @return array<string, int>
     */
    public static function foldIdsByCode(array $idsByCode, ?Event $event): array
    {
        foreach (self::foldedCodes($event) as $code) {
            $countedCode = self::WFD_ONLY[$code];

            if (isset($idsByCode[$code], $idsByCode[$countedCode])) {
                $idsByCode[$code] = $idsByCode[$countedCode];
            }
        }

        return $idsByCode;
    }

    /**
     * The id of the section a logged section id counts as for the event.
     *
     * Falls back to the given id when the section isn't folded or the
     * counted section isn't active.
     */
    public static function countedSectionId(int $sectionId, ?Event $event): int
    {
        if (self::recognisesWfdSections($event)) {
            return $sectionId;
        }

        $code = Section::query()->whereKey($sectionId)->value('code');
        $countedCode = self::WFD_ONLY[$code ?? ''] ?? null;

        if ($countedCode === null) {
            return $sectionId;
        }

        return Section::query()
            ->where('code', $countedCode)
            ->where('is_active', true)
            ->value('id') ?? $sectionId;
    }
}
