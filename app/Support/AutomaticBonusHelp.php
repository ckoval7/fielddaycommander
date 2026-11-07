<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
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
            'sm_sec_message' => 'Earned when a message to your Section Manager or Section Emergency Coordinator is marked sent in Message Traffic.',
            'nts_message' => 'Counts each other NTS message in Message Traffic that is marked sent, or received and delivered, up to the limit.',
            'w1aw_bulletin', 'special_bulletin', 'bulletin' => "Earned when the {$terms->bulletinName} copy is saved.",
            'elected_official_visit' => 'Earned when a guestbook entry in the Elected Official category is verified.',
            'agency_visit' => 'Earned when a guestbook entry in the Agency category is verified.',
            'emergency_power' => 'Earned when the event setup leaves Commercial Power (Grid) unchecked and no station runs on commercial mains. Points go up with your transmitter count.',
            'alternative_power' => 'Earned when the event setup leaves Commercial Power (Grid) unchecked and no station runs on commercial mains.',
            'satellite_qso' => 'Earned with your first satellite contact that isn\'t a dupe.',
            'safety_officer' => 'Earned when every item on the Safety Officer checklist in Site Safety is checked off.',
            'site_responsibilities' => 'Earned when every required item, and more than half of all items, on the Site Responsibilities checklist in Site Safety are checked off.',
            'youth_participation' => 'Counts registered youth who log a contact. Add youth who don\'t have an account in the Youth Participation card below.',
            'six_bands' => 'Earned once six bands each have at least three contacts that aren\'t dupes.',
            'twelve_bands' => 'Earned once twelve bands each have at least three contacts that aren\'t dupes.',
            'multiple_modes' => 'Earned once the log has contacts in two of the three mode groups: CW, Phone, and Digital.',
            'qrp' => 'Earned while every contact in the log is at 10 W or less on Phone, or 5 W or less on CW and Digital. A single contact over the limit loses it.',
            default => 'Tracked automatically from the log and event setup.',
        };
    }
}
