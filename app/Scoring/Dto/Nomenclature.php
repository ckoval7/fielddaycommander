<?php

namespace App\Scoring\Dto;

/**
 * The words a rulebook uses for its own concepts.
 *
 * ARRL Field Day awards "bonuses" worth points; Winter Field Day awards
 * "objectives" worth multipliers. Operators read the published terminology
 * when deciding what they qualify for, so the UI follows whichever rulebook
 * the event is pinned to instead of inventing neutral wording.
 */
final class Nomenclature
{
    public function __construct(
        public readonly string $awardSingular = 'Bonus',
        public readonly string $awardPlural = 'Bonuses',
        public readonly string $awardSectionTitle = 'Bonus Points',
        public readonly string $claimsTitle = 'Manual Bonus Claims',
        public readonly string $awardValueLabel = 'Points',
        /**
         * True when this rulebook's awards carry a multiplier rather than a
         * point value, so the UI shows "OM x3" in place of "300 pts".
         */
        public readonly bool $awardsAreMultipliers = false,
        /**
         * The body that publishes this rulebook, as operators see it cited
         * next to a rule — "ARRL Rule 7.3.1", "WFDA Rule Objective &
         * Multipliers".
         */
        public readonly string $rulebookName = 'ARRL',
        /**
         * What operators call the over-the-air bulletin they copy for credit —
         * ARRL's W1AW Field Day bulletin, or the bulletin WFDA publishes.
         */
        public readonly string $bulletinName = 'W1AW Bulletin',
        /**
         * Stations the rulebook names as sending the bulletin, offered as the
         * schedule's source choices. Empty when the rulebook does not fix the
         * senders, so the schedule takes a free-text callsign instead.
         *
         * @var list<string>
         */
        public readonly array $bulletinSources = ['W1AW', 'K6KPH'],
        /**
         * Badge shown once the bulletin copy is saved and its award earned.
         */
        public readonly string $bulletinCapturedLabel = 'Bonus Earned: 100 pts',
    ) {}
}
