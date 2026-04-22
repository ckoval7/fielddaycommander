<?php

namespace App\Scoring\Rules;

use App\Scoring\Dto\PowerContext;

/**
 * Synthetic FD ruleset used solely to exercise the admin rescore / version
 * swap flow. NOT an ARRL-published ruleset and NOT intended for any real
 * event submission.
 *
 *  - 6-100 W low-power multiplier returns '3' instead of the usual '2'.
 *  - Introduces a "Use Field Day Commander" bonus (code: use_fd_commander)
 *    worth a flat 100 points, seeded via migration against rules_version='TEST'.
 *
 * Scoring a real event against this ruleset would produce a fabricated score
 * that does not match ARRL rules, so keep it gated to staging/demo usage.
 */
class FieldDayTest extends FieldDay2025
{
    public function id(): string
    {
        return 'FD-TEST';
    }

    public function version(): string
    {
        return 'TEST';
    }

    public function strategies(): array
    {
        return [];
    }

    public function powerMultiplier(PowerContext $ctx): string
    {
        if ($ctx->effectivePowerWatts > self::LOW_WATT_CEILING) {
            return '1';
        }

        if ($ctx->effectivePowerWatts <= self::QRP_WATT_CEILING && $ctx->qualifiesForQrpNaturalBonus) {
            return '5';
        }

        return '3';
    }
}
