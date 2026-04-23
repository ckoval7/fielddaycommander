<?php

namespace App\Scoring\Rules;

/**
 * ARRL Field Day 2026 scoring rules.
 *
 * As of creation: no ARRL-announced changes; inherits 2025 verbatim. When
 * ARRL publishes the 2026 tweaks, override specific methods here and/or
 * seed bonus_types / mode_rule_points rows with rules_version='2026'.
 *
 * DO NOT modify FieldDay2025 to apply 2026 changes — that would alter
 * historical 2025 scores.
 */
class FieldDay2026 extends FieldDay2025
{
    public function id(): string
    {
        return 'FD-2026';
    }

    public function version(): string
    {
        return '2026';
    }
}
