<?php

namespace App\Scoring\Contracts;

use App\Models\BonusType;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Dto\PowerContext;

/**
 * Immutable per-year, per-event-type scoring rules.
 *
 * Every method returning a number or string is the source of truth for that
 * year's rule. Implementations must never read the current date, feature flags,
 * or any global state that could change historical results.
 */
interface RuleSet
{
    /**
     * Machine identifier — e.g. "FD-2025" or "WFD-2025". Shown in reports.
     */
    public function id(): string;

    /**
     * The rules_version string this implementation handles (e.g. "2025").
     */
    public function version(): string;

    /**
     * Event type code this ruleset applies to (e.g. "FD").
     */
    public function eventTypeCode(): string;

    // -- Per-contact point values --

    /**
     * Points awarded for a single non-duplicate, non-GOTA contact.
     * GOTA contacts are always flat 5 (see gotaPointsPerContact()).
     */
    public function pointsForContact(Mode $mode, Station $station): int;

    /**
     * Flat points for each non-duplicate GOTA contact, ignored by QSO multiplier.
     */
    public function gotaPointsPerContact(): int;

    // -- Multiplier --

    /**
     * Returns '1', '2', or '5' (string, to match stored `power_multiplier`).
     */
    public function powerMultiplier(PowerContext $ctx): string;

    // -- Bonuses that are fully formula-driven (no DB row) --

    public function gotaCoachThreshold(): int;

    public function gotaCoachBonus(): int;

    public function youthMaxCount(): int;

    public function youthPointsPerYouth(): int;

    /**
     * Max number of transmitters eligible for the emergency-power bonus.
     */
    public function emergencyPowerMaxTransmitters(): int;

    // -- Bonus row lookup (partitioned by rules_version) --

    /**
     * Resolve a BonusType row for this ruleset by its code.
     * Returns null if the code is not defined for this version.
     */
    public function bonus(string $code): ?BonusType;

    /**
     * Bonus strategies owned by this ruleset, keyed by bonus code.
     *
     * Returns a map of code => FQCN of a BonusStrategy implementation.
     * Subclasses override via `array_merge(parent::strategies(), [...])`.
     *
     * @return array<string, class-string<BonusStrategy>>
     */
    public function strategies(): array;

    /**
     * Versioned rulebook reference for a bonus code.
     *
     * Covers every bonus this ruleset exposes — both strategy-driven codes
     * and codes scored directly by the ruleset (e.g. emergency_power,
     * gota_qso). Returns null for codes this version does not define.
     *
     * @return array{section: string, text: string}|null
     */
    public function bonusRuleReference(string $code): ?array;
}
