<?php

namespace App\Scoring\Contracts;

use App\Models\EventConfiguration;

/**
 * Versioned bonus strategy. One implementation per (rules_version, bonus code).
 *
 * A strategy owns the answer to "for this bonus, how does it get triggered
 * and how are its points calculated for this ruleset year?" — both questions
 * that used to live in globally-fired sync services.
 *
 * Strategies are instantiated by the RuleSet (via its strategies() map) so
 * they inherit cleanly from year to year through PHP inheritance.
 */
interface BonusStrategy
{
    /**
     * The bonus_types.code this strategy handles, e.g. 'sm_sec_message'.
     */
    public function code(): string;

    /**
     * One of: 'manual', 'derived', 'hybrid'.
     *
     * - manual: UI creates the EventBonus row. reconcile() is a no-op.
     * - derived: reconcile() computes everything from observable state.
     * - hybrid: reconcile() combines observable state with a manual adjustment
     *   persisted on event_bonuses.manual_quantity_adjustment.
     */
    public function triggerType(): string;

    /**
     * FQCNs of domain events that should cause reconcile() to run.
     * Returns [] for pure manual strategies.
     *
     * @return array<int, class-string>
     */
    public function subscribesTo(): array;

    /**
     * Read current state, write/update/delete exactly one EventBonus row for
     * $this->code() on $config. Idempotent.
     */
    public function reconcile(EventConfiguration $config): void;
}
