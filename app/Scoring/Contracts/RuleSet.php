<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Contracts;

use App\Models\BonusType;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;

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
     * Points awarded for a single non-duplicate contact.
     *
     * Rulesets apply their own per-class handling here (e.g. Field Day's flat
     * GOTA rate, or Winter Field Day excluding satellite QSOs from QSO credit).
     */
    public function pointsForContact(Mode $mode, Station $station): int;

    // -- Score composition --

    /**
     * Compose this configuration's full score under this rulebook.
     *
     * Owning the composition here is what lets one scoring UI serve rulebooks
     * that combine their parts differently — Field Day adds bonus points to a
     * power-multiplied QSO score, Winter Field Day multiplies QSO points by an
     * objective multiplier.
     */
    public function score(EventConfiguration $config): ScoreBreakdown;

    /**
     * The words this rulebook uses for its awards, for UI labelling.
     */
    public function nomenclature(): Nomenclature;

    /**
     * Cabrillo CONTEST: identifier for logs submitted under this rulebook.
     *
     * Submitting a Winter Field Day log tagged ARRL-FD would be rejected by
     * the receiving robot, so the value comes from the rulebook.
     */
    public function cabrilloContestName(): string;

    /**
     * Slug used in exported log filenames, e.g. "field-day".
     */
    public function logFilenameSlug(): string;

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
     * Return the strategy classes that subscribe to the given domain event class.
     *
     * Used by the reconcile listener to skip instantiating strategies that do not
     * care about the event being dispatched. Must return class-strings only.
     *
     * @param  class-string  $eventClass
     * @return array<int, class-string<BonusStrategy>>
     */
    public function strategiesFor(string $eventClass): array;

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
