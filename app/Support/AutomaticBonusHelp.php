<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Scoring\Dto\Nomenclature;

/**
 * Plain-language notes on how the app earns each automatic bonus or
 * objective, shown beside it on the claims card so managers know where the
 * credit comes from and that they don't need to claim it.
 *
 * Keyed by bonus code. The notes describe what the app watches, not the
 * rulebook wording, which the rule help on each row already shows.
 */
final class AutomaticBonusHelp
{
    /**
     * The note for an automatic bonus code, or a general one for a code with
     * no note of its own.
     */
    public static function for(string $code, Nomenclature $terms): string
    {
        return match ($code) {
            'sm_sec_message' => 'In Message Traffic, flag the Section Manager/SEC message and mark it sent, or received and delivered.',
            'nts_message' => 'In Message Traffic, mark other messages sent, or received and delivered.',
            'w1aw_bulletin', 'special_bulletin', 'bulletin' => "Save a copy of the {$terms->bulletinName}.",
            'elected_official_visit' => 'Verify an Elected Official guestbook entry.',
            'agency_visit' => 'Verify an Agency guestbook entry.',
            'emergency_power' => 'Uncheck Commercial Power (Grid) in event setup and use no commercial mains for stations or logged contacts.',
            'alternative_power' => 'Uncheck Commercial Power (Grid) in event setup and use no commercial mains for stations.',
            'satellite_qso' => 'Log a non-dupe satellite contact.',
            'safety_officer' => 'Check off every Safety Officer checklist item in Site Safety.',
            'site_responsibilities' => 'In Site Safety, check off all required items and more than half of all items on the Site Responsibilities checklist.',
            'youth_participation' => 'On Youth Participation below, flag youth with non-dupe contacts or add a count.',
            'six_bands' => 'Log at least three qualifying contacts on each of six bands.',
            'twelve_bands' => 'Log at least three qualifying contacts on each of twelve bands.',
            'multiple_modes' => 'Log qualifying contacts in at least two of CW, Phone, and Digital.',
            'qrp' => "Keep every non-dupe contact in the log at 10\u{00A0}W or less on Phone, or 5\u{00A0}W or less on CW and Digital.",
            default => 'See the log and event setup.',
        };
    }
}
