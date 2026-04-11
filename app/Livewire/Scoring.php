<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasBandModeGrid;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Services\EventContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Scoring extends Component
{
    use HasBandModeGrid;

    public ?Event $event = null;

    public function mount(): void
    {
        $this->event = app(EventContextService::class)->getContextEvent();

        $this->event?->load([
            'eventConfiguration.section',
            'eventConfiguration.operatingClass',
            'eventConfiguration.bonuses',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->event) {
            return [];
        }

        return [
            "echo-private:event.{$this->event->id},ContactLogged" => 'handleContactLogged',
        ];
    }

    /**
     * Handle real-time ContactLogged broadcast.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleContactLogged(array $payload): void
    {
        $this->clearComputedCache();
    }

    /**
     * Get the active event configuration.
     */
    protected function config(): ?EventConfiguration
    {
        return $this->event?->eventConfiguration;
    }

    // ========================================================================
    // SCORE TOTALS
    // ========================================================================

    #[Computed]
    public function qsoBasePoints(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return (int) $this->config()->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', false)
            ->sum('points');
    }

    #[Computed]
    public function powerMultiplier(): int
    {
        return $this->config()?->calculatePowerMultiplier() ?? 1;
    }

    #[Computed]
    public function qsoScore(): int
    {
        return $this->config()?->calculateQsoScore() ?? 0;
    }

    #[Computed]
    public function gotaBonus(): int
    {
        return $this->config()?->calculateGotaBonus() ?? 0;
    }

    #[Computed]
    public function gotaCoachBonus(): int
    {
        return $this->config()?->calculateGotaCoachBonus() ?? 0;
    }

    #[Computed]
    public function gotaTotalBonus(): int
    {
        return $this->gotaBonus + $this->gotaCoachBonus;
    }

    #[Computed]
    public function gotaSupervisedCount(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
            ->whereHas('operatingSession', fn ($q) => $q->where('is_supervised', true))
            ->count();
    }

    #[Computed]
    public function bonusScore(): int
    {
        return $this->config()?->calculateBonusScore() ?? 0;
    }

    #[Computed]
    public function finalScore(): int
    {
        return $this->config()?->calculateFinalScore() ?? 0;
    }

    // ========================================================================
    // QSO BREAKDOWN
    // ========================================================================

    #[Computed]
    public function totalContacts(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_gota_contact', false)
            ->count();
    }

    #[Computed]
    public function validContacts(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', false)
            ->count();
    }

    #[Computed]
    public function duplicateCount(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', true)
            ->where('is_gota_contact', false)
            ->count();
    }

    #[Computed]
    public function duplicateRate(): float
    {
        $total = $this->totalContacts;

        if ($total === 0) {
            return 0.0;
        }

        return round(($this->duplicateCount / $total) * 100, 1);
    }

    #[Computed]
    public function gotaContactCount(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
            ->count();
    }

    #[Computed]
    public function zeroPointContactCount(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', false)
            ->where('points', 0)
            ->count();
    }

    // ========================================================================
    // BAND/MODE GRID (via HasBandModeGrid trait)
    // ========================================================================

    protected function bandModeGridQuery(): Builder
    {
        return Contact::where('event_configuration_id', $this->config()->id)
            ->notDuplicate()
            ->where('is_gota_contact', false);
    }

    // ========================================================================
    // BONUS LIST
    // ========================================================================

    #[Computed]
    public function bonusList(): array
    {
        $eventTypeId = $this->event?->event_type_id;

        $classCode = $this->config()?->operatingClass?->code;

        $query = BonusType::where('is_active', true);
        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }
        $bonusTypes = $query->orderByDesc('base_points')->get()
            ->filter(fn (BonusType $bt) => $bt->eligible_classes === null
                || ($classCode !== null && in_array($classCode, $bt->eligible_classes, true)));

        $claimedBonuses = $this->config()
            ? $this->config()->bonuses->keyBy('bonus_type_id')
            : collect();

        $youthPoints = $this->config()?->calculateYouthBonus() ?? 0;
        $emergencyPowerPoints = $this->config()?->calculateEmergencyPowerBonus() ?? 0;
        $satellitePoints = $this->config()?->calculateSatelliteBonus() ?? 0;

        $list = [];

        $computedPoints = [
            'youth_participation' => $youthPoints,
            'emergency_power' => $emergencyPowerPoints,
            'satellite_qso' => $satellitePoints,
        ];

        foreach ($bonusTypes as $bonusType) {
            if (isset($computedPoints[$bonusType->code])) {
                $pts = $computedPoints[$bonusType->code];
                $list[] = [
                    'type' => $bonusType,
                    'bonus' => null,
                    'status' => $pts > 0 ? 'verified' : 'unclaimed',
                    'points' => $pts,
                ];

                continue;
            }

            $eventBonus = $claimedBonuses->get($bonusType->id);
            $list[] = $this->buildBonusEntry($bonusType, $eventBonus);
        }

        return $list;
    }

    private function buildBonusEntry(mixed $bonusType, mixed $eventBonus): array
    {
        if ($eventBonus && $eventBonus->is_verified) {
            $status = 'verified';
            $points = (int) $eventBonus->calculated_points;
        } elseif ($eventBonus) {
            $status = 'claimed';
            $points = (int) $eventBonus->calculated_points;
        } else {
            $status = 'unclaimed';
            $points = 0;
        }

        return [
            'type' => $bonusType,
            'bonus' => $eventBonus,
            'status' => $status,
            'points' => $points,
        ];
    }

    /**
     * Aggregated bonus point totals for the summary row.
     * Depends on the bonusList computed property.
     *
     * @return array{verified_pts: int, claimed_pts: int, unclaimed_count: int}
     */
    #[Computed]
    public function bonusSummary(): array
    {
        $list = collect($this->bonusList);

        return [
            'verified_pts' => (int) $list->where('status', 'verified')->sum('points'),
            'claimed_pts' => (int) $list->where('status', 'claimed')->sum('points'),
            'unclaimed_count' => $list->where('status', 'unclaimed')->count(),
        ];
    }

    // ========================================================================
    // POWER SOURCES
    // ========================================================================

    /**
     * @return array<string, array{label: string, active: bool}>
     */
    #[Computed]
    public function powerSources(): array
    {
        $config = $this->config();

        return [
            'commercial' => ['label' => 'Commercial Power', 'active' => (bool) $config?->uses_commercial_power],
            'generator' => ['label' => 'Generator', 'active' => (bool) $config?->uses_generator],
            'battery' => ['label' => 'Battery', 'active' => (bool) $config?->uses_battery],
            'solar' => ['label' => 'Solar', 'active' => (bool) $config?->uses_solar],
            'wind' => ['label' => 'Wind', 'active' => (bool) $config?->uses_wind],
            'water' => ['label' => 'Water', 'active' => (bool) $config?->uses_water],
            'methane' => ['label' => 'Methane', 'active' => (bool) $config?->uses_methane],
        ];
    }

    // ========================================================================
    // POWER MULTIPLIER REASON
    // ========================================================================

    #[Computed]
    public function powerMultiplierReason(): string
    {
        $config = $this->config();

        if (! $config) {
            return 'No active event configuration.';
        }

        $effectiveWatts = $config->effectiveMaxPowerWatts();
        $configWatts = $config->max_power_watts;
        $stationOverride = $effectiveWatts > $configWatts;
        $stationNote = $stationOverride
            ? " A station is configured at {$effectiveWatts}W, which overrides the event setting of {$configWatts}W."
            : '';

        if ($effectiveWatts > 100) {
            return "Operating at {$effectiveWatts}W (over 100W) gives a 1\u{00d7} multiplier.{$stationNote}";
        }

        if ($effectiveWatts > 5) {
            return "Operating at {$effectiveWatts}W (6\u{2013}100W) gives a 2\u{00d7} multiplier.{$stationNote}";
        }

        // QRP (5W or less) - determine reason based on power sources
        $hasNaturalPower = $config->uses_battery || $config->uses_solar || $config->uses_wind || $config->uses_water;
        $hasDisqualifyingPower = $config->uses_commercial_power || $config->uses_generator;

        return match (true) {
            $hasNaturalPower && ! $hasDisqualifyingPower => "Operating at {$effectiveWatts}W with natural power and no commercial/generator power qualifies for the 5\u{00d7} QRP natural power bonus.",
            $hasDisqualifyingPower => "Operating at {$effectiveWatts}W (QRP) gives a 2\u{00d7} multiplier. Switch to natural power only to qualify for 5\u{00d7}.",
            default => "Operating at {$effectiveWatts}W (QRP) gives a 2\u{00d7} multiplier.",
        };
    }

    // ========================================================================
    // POWER MULTIPLIER RULES TABLE
    // ========================================================================

    /**
     * Rules table for the power multiplier, with the active row flagged.
     * Depends on the powerMultiplier computed property.
     *
     * @return array<int, array{condition: string, multiplier: string, active: bool}>
     */
    #[Computed]
    public function powerMultiplierRules(): array
    {
        $config = $this->config();
        $watts = $config?->max_power_watts ?? 0;
        $multi = $this->powerMultiplier;

        return [
            [
                'condition' => '≤ 5W + natural power, no commercial/generator',
                'multiplier' => '5×',
                'active' => $multi === 5,
            ],
            [
                'condition' => '≤ 5W + commercial or generator power',
                'multiplier' => '2×',
                'active' => $multi === 2 && $watts <= 5,
            ],
            [
                'condition' => '6 – 100W (any power source)',
                'multiplier' => '2×',
                'active' => $multi === 2 && $watts > 5 && $watts <= 100,
            ],
            [
                'condition' => 'Over 100W',
                'multiplier' => '1×',
                'active' => $multi === 1,
            ],
        ];
    }

    // ========================================================================
    // NOTICES
    // ========================================================================

    /**
     * @return array<int, array{severity: string, section: string, message: string}>
     */
    #[Computed]
    public function notices(): array
    {
        if (! $this->config()) {
            return [];
        }

        $notices = [];

        // High duplicate rate warning
        $rate = $this->duplicateRate;
        if ($rate > 5) {
            $notices[] = [
                'severity' => 'warning',
                'section' => 'qso',
                'message' => "High duplicate rate ({$rate}%) \u{2014} review log for callsign entry errors.",
            ];
        }

        // Zero-point contacts error
        $zeroCount = $this->zeroPointContactCount;
        if ($zeroCount > 0) {
            $notices[] = [
                'severity' => 'error',
                'section' => 'qso',
                'message' => "{$zeroCount} contact(s) logged with 0 points \u{2014} check band/mode assignment.",
            ];
        }

        // GOTA contacts without GOTA station
        $gotaCount = $this->gotaContactCount;
        if ($gotaCount > 0 && ! $this->config()->has_gota_station) {
            $notices[] = [
                'severity' => 'error',
                'section' => 'qso',
                'message' => "{$gotaCount} GOTA contact(s) logged but no GOTA station is configured.",
            ];
        }

        // Unverified claimed bonuses
        $unverifiedCount = collect($this->bonusList)
            ->filter(fn ($b) => $b['status'] === 'claimed')
            ->count();
        if ($unverifiedCount > 0) {
            $notices[] = [
                'severity' => 'warning',
                'section' => 'bonus',
                'message' => "{$unverifiedCount} bonus(es) claimed but not yet verified.",
            ];
        }

        // QRP power opportunity: <=5W but only 2x because no natural power
        $watts = $this->config()->max_power_watts;
        $multiplier = $this->powerMultiplier;
        if ($watts <= 5 && $multiplier === 2) {
            $hasAnyNaturalPower = $this->config()->uses_battery
                || $this->config()->uses_solar
                || $this->config()->uses_wind
                || $this->config()->uses_water;
            if (! $hasAnyNaturalPower) {
                $notices[] = [
                    'severity' => 'opportunity',
                    'section' => 'power',
                    'message' => "Running {$watts}W QRP \u{2014} add a natural power source (battery, solar, wind, water) to qualify for the 5\u{00d7} multiplier.",
                ];
            }
        }

        return $notices;
    }

    // ========================================================================
    // REFERENCE DATA
    // ========================================================================

    #[Computed]
    public function bands(): Collection
    {
        return Band::allowedForFieldDay()->ordered()->get();
    }

    #[Computed]
    public function modes(): Collection
    {
        return Mode::orderBy('name')->get();
    }

    // ========================================================================
    // CACHE MANAGEMENT
    // ========================================================================

    /**
     * Clear all computed property caches.
     */
    protected function clearComputedCache(): void
    {
        unset(
            $this->qsoBasePoints,
            $this->powerMultiplier,
            $this->qsoScore,
            $this->bonusScore,
            $this->finalScore,
            $this->totalContacts,
            $this->validContacts,
            $this->duplicateCount,
            $this->duplicateRate,
            $this->gotaContactCount,
            $this->zeroPointContactCount,
            $this->gotaBonus,
            $this->gotaCoachBonus,
            $this->gotaTotalBonus,
            $this->gotaSupervisedCount,
            $this->bandModeGrid,
            $this->bandColumnTotals,
            $this->bonusList,
            $this->bonusSummary,
            $this->powerSources,
            $this->powerMultiplierReason,
            $this->powerMultiplierRules,
            $this->notices,
            $this->bands,
            $this->modes,
        );
    }

    public function render(): View
    {
        return view('livewire.scoring');
    }
}
