<?php

namespace App\Scoring\Dto;

/**
 * Immutable snapshot of what the power multiplier function needs to know.
 *
 * Keeps the RuleSet interface decoupled from the EventConfiguration model.
 */
final readonly class PowerContext
{
    public function __construct(
        public int $effectivePowerWatts,
        public bool $qualifiesForQrpNaturalBonus,
    ) {}
}
