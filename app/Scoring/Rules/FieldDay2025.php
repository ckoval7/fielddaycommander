<?php

namespace App\Scoring\Rules;

use App\Models\BonusType;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Dto\PowerContext;

/**
 * ARRL Field Day 2025 scoring rules.
 *
 * FROZEN. Do not modify this file after merge. Any ARRL 2026 rule tweak
 * goes into a new FieldDay2026 class — see
 * docs/scoring/adding-a-rules-version.md.
 */
class FieldDay2025 implements RuleSet
{
    protected const QRP_WATT_CEILING = 5;

    protected const LOW_WATT_CEILING = 100;

    protected ?int $cachedEventTypeId = null;

    /** @var array<string, ?BonusType> */
    protected array $cachedBonuses = [];

    public function id(): string
    {
        return 'FD-2025';
    }

    public function version(): string
    {
        return '2025';
    }

    public function eventTypeCode(): string
    {
        return 'FD';
    }

    public function pointsForContact(Mode $mode, Station $station): int
    {
        if ($station->is_gota) {
            return $this->gotaPointsPerContact();
        }

        $override = ModeRulePoint::query()
            ->whereHas('eventType', fn ($q) => $q->where('code', $this->eventTypeCode()))
            ->where('rules_version', $this->version())
            ->where('mode_id', $mode->id)
            ->value('points');

        return (int) ($override ?? $mode->points_fd ?? 1);
    }

    public function gotaPointsPerContact(): int
    {
        return 5;
    }

    public function powerMultiplier(PowerContext $ctx): string
    {
        if ($ctx->effectivePowerWatts > self::LOW_WATT_CEILING) {
            return '1';
        }

        if ($ctx->effectivePowerWatts <= self::QRP_WATT_CEILING && $ctx->qualifiesForQrpNaturalBonus) {
            return '5';
        }

        return '2';
    }

    public function gotaCoachThreshold(): int
    {
        return 10;
    }

    public function gotaCoachBonus(): int
    {
        return 100;
    }

    public function youthMaxCount(): int
    {
        return 5;
    }

    public function youthPointsPerYouth(): int
    {
        return 20;
    }

    public function emergencyPowerMaxTransmitters(): int
    {
        return 20;
    }

    public function strategies(): array
    {
        return [];
    }

    public function bonus(string $code): ?BonusType
    {
        if (array_key_exists($code, $this->cachedBonuses)) {
            return $this->cachedBonuses[$code];
        }

        $eventTypeId = $this->cachedEventTypeId ??= EventType::query()
            ->where('code', $this->eventTypeCode())
            ->value('id');

        if (! $eventTypeId) {
            return $this->cachedBonuses[$code] = null;
        }

        return $this->cachedBonuses[$code] = BonusType::query()
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', $this->version())
            ->where('code', $code)
            ->first();
    }
}
