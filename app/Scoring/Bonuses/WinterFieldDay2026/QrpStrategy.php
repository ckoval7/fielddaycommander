<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;
use Illuminate\Database\Eloquent\Builder;

/**
 * WFD 2026 objective "qrp". OM x4.
 *
 * SOP: "Operating on QRP means every station in your Winter Field Day operation
 * is using 10 watts or less on Phone or 5 watts or less on CW or Digital for the
 * entire time you choose to operate during the event."
 *
 * The two ceilings are independent: an operation running 10 W on Phone and 5 W
 * on CW satisfies both and earns the objective. Each contact is therefore judged
 * against the ceiling for its own mode, using the power actually recorded on the
 * QSO rather than a single figure for the whole operation.
 *
 * Contacts predating per-QSO power capture (or imported without it) have no
 * power_watts of their own; those fall back to the operation's configured
 * maximum, which cannot understate the power used.
 */
class QrpStrategy extends AbstractBonusStrategy
{
    private const PHONE_WATT_CEILING = 10;

    private const CW_DIGITAL_WATT_CEILING = 5;

    /** Mode categories held to the stricter CW/Digital ceiling. */
    private const STRICT_CEILING_CATEGORIES = ['CW', 'Digital'];

    public function code(): string
    {
        return 'qrp';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [QsoLogged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $achieved = $config->hasContacts() && $this->everyContactWithinItsCeiling($config);

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }

    /**
     * Whether every non-duplicate contact respected its own mode's ceiling.
     *
     * A single contact over its ceiling disqualifies the operation, so this
     * stops at the first exceedance rather than tallying them. A contact with
     * no mode, or a mode with no category, is held to the stricter ceiling so
     * an unclassified mode cannot buy the objective.
     */
    private function everyContactWithinItsCeiling(EventConfiguration $config): bool
    {
        $fallbackWatts = $config->effectiveMaxPowerWatts();
        $watts = 'COALESCE(power_watts, ?)';

        $exceeded = $config->contacts()
            ->where('is_duplicate', false)
            ->where(function (Builder $query) use ($watts, $fallbackWatts) {
                // Over the Phone ceiling exceeds every mode's ceiling.
                $query->whereRaw("{$watts} > ?", [$fallbackWatts, self::PHONE_WATT_CEILING])
                    ->orWhere(function (Builder $query) use ($watts, $fallbackWatts) {
                        $query->whereRaw("{$watts} > ?", [$fallbackWatts, self::CW_DIGITAL_WATT_CEILING])
                            ->where(fn (Builder $query) => $query
                                ->whereNull('mode_id')
                                ->orWhereHas('mode', fn (Builder $mode) => $mode
                                    ->whereNull('category')
                                    ->orWhereIn('category', self::STRICT_CEILING_CATEGORIES)));
                    });
            })
            ->exists();

        return ! $exceeded;
    }
}
