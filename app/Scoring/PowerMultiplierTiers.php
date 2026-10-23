<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring;

use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Dto\PowerContext;

/**
 * Describes a Field Day rulebook's power multiplier tiers for display.
 *
 * The tiers are read from the rulebook's own powerMultiplier() by probing it
 * at every whole wattage, with and without natural power. The rule sets keep
 * their thresholds private and are frozen, so this lets the UI show each
 * version's limits without a hard-coded copy that could drift from scoring.
 */
final class PowerMultiplierTiers
{
    /**
     * Highest wattage probed: the US legal limit. A tier still in force here
     * has no upper bound.
     */
    public const PROBE_CEILING_WATTS = 1500;

    /**
     * Tiers per rule set class. Rule sets are frozen, so a class's tiers never
     * change; this keeps the probing to once per version per worker.
     *
     * @var array<class-string, list<array{multiplier: string, minWatts: int, maxWatts: ?int, naturalPowerOnly: bool}>>
     */
    private static array $cache = [];

    /**
     * Tiers ordered from the highest multiplier down.
     *
     * Each tier spans the lowest to highest wattage that earned its
     * multiplier. naturalPowerOnly is true when the multiplier was earned
     * only with natural power. A rulebook whose tiers aren't contiguous
     * wattage ranges would be summarised by its outer bounds.
     *
     * @return list<array{multiplier: string, minWatts: int, maxWatts: ?int, naturalPowerOnly: bool}>
     */
    public static function for(FieldDayRuleSet $rules): array
    {
        return self::$cache[$rules::class] ??= self::probe($rules);
    }

    /**
     * @return list<array{multiplier: string, minWatts: int, maxWatts: ?int, naturalPowerOnly: bool}>
     */
    private static function probe(FieldDayRuleSet $rules): array
    {
        /** @var array<string, array{min: int, max: int, withoutNatural: bool}> $seen */
        $seen = [];

        foreach ([true, false] as $natural) {
            for ($watts = 1; $watts <= self::PROBE_CEILING_WATTS; $watts++) {
                $multiplier = $rules->powerMultiplier(new PowerContext($watts, $natural));

                $tier = $seen[$multiplier] ?? ['min' => $watts, 'max' => $watts, 'withoutNatural' => false];
                $tier['min'] = min($tier['min'], $watts);
                $tier['max'] = max($tier['max'], $watts);
                $tier['withoutNatural'] = $tier['withoutNatural'] || ! $natural;

                $seen[$multiplier] = $tier;
            }
        }

        uksort($seen, fn (string|int $a, string|int $b): int => (int) $b <=> (int) $a);

        $tiers = [];

        foreach ($seen as $multiplier => $tier) {
            $tiers[] = [
                'multiplier' => (string) $multiplier,
                'minWatts' => $tier['min'],
                'maxWatts' => $tier['max'] >= self::PROBE_CEILING_WATTS ? null : $tier['max'],
                'naturalPowerOnly' => ! $tier['withoutNatural'],
            ];
        }

        return $tiers;
    }
}
