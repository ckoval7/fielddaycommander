<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire;

use App\Livewire\Concerns\HasBandModeGrid;
use App\Livewire\Concerns\ResolvesBonusRule;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Contracts\ImpliesObjectives;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;
use App\Scoring\Dto\ScoreTerm;
use App\Scoring\RuleSetFactory;
use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Scoring extends Component
{
    use HasBandModeGrid;
    use ResolvesBonusRule;

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

        return BroadcastChannels::contactChangeListeners($this->event->id, 'handleContactLogged');
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

    /**
     * QSO points as the event's ruleset counts them.
     *
     * Delegating to the breakdown keeps this figure identical to the one in the
     * headline equation — Winter Field Day excludes satellite QSOs from QSO
     * credit, so a raw sum over contacts would disagree with the score shown.
     */
    #[Computed]
    public function qsoBasePoints(): int
    {
        return $this->breakdown?->value('qso_points') ?? 0;
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

    /**
     * The pinned ruleset's own score composition, or null with no config.
     */
    #[Computed]
    public function breakdown(): ?ScoreBreakdown
    {
        return $this->config()?->scoreBreakdown();
    }

    /**
     * Ordered headline equation parts supplied by the ruleset.
     *
     * @return array<int, ScoreTerm|string>
     */
    #[Computed]
    public function headline(): array
    {
        return $this->breakdown?->headline ?? [];
    }

    /**
     * The award wording this event's rulebook uses ("Bonus" vs "Objective").
     */
    #[Computed]
    public function terms(): Nomenclature
    {
        return $this->config()?->nomenclature() ?? new Nomenclature;
    }

    /**
     * Whether this rulebook scores with a power multiplier at all.
     *
     * Winter Field Day caps every station at 100 W PEP and has no multiplier,
     * so the power column and its explainer are hidden for those events.
     */
    #[Computed]
    public function usesPowerMultiplier(): bool
    {
        return $this->config()?->usesPowerMultiplier() ?? true;
    }

    /**
     * Objective completion for rulebooks that track it, as {achieved, available, percent}.
     *
     * WFDA records the percentage of objectives completed year over year, so
     * it belongs next to the score rather than only in the multiplier.
     *
     * @return array{achieved: int, available: int, percent: int}|null
     */
    #[Computed]
    public function objectiveProgress(): ?array
    {
        $breakdown = $this->breakdown;
        $achieved = $breakdown?->line('objectives_achieved');

        if (! $breakdown || ! $achieved) {
            return null;
        }

        $available = $breakdown->value('objectives_available');

        return [
            'achieved' => $achieved->value,
            'available' => $available,
            'percent' => $available > 0 ? (int) round($achieved->value / $available * 100) : 0,
        ];
    }

    /**
     * Whether this rulebook's awards are multipliers rather than point values.
     */
    #[Computed]
    public function awardsAreMultipliers(): bool
    {
        return $this->terms->awardsAreMultipliers;
    }

    // ========================================================================
    // QSO BREAKDOWN
    // ========================================================================

    /**
     * Every contact logged for the event, GOTA and dupes included: the
     * logbook's unfiltered row count.
     */
    #[Computed]
    public function totalContacts(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()->count();
    }

    /**
     * Non-duplicate contacts, GOTA included: the QSO count the dashboard and
     * logbook show.
     */
    #[Computed]
    public function validContacts(): int
    {
        if (! $this->config()) {
            return 0;
        }

        return $this->config()->contacts()
            ->where('is_duplicate', false)
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
            ->count();
    }

    /**
     * Non-duplicate contacts the rulebook logs but gives no credit (e.g. WFD
     * 2027 WSJT-mode and satellite contacts). Zero for rulebooks without such rules.
     */
    #[Computed]
    public function noCreditContactCount(): int
    {
        return $this->breakdown?->value('no_credit_contacts') ?? 0;
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
            ->where('points', 0)
            ->count();
    }

    // ========================================================================
    // BAND/MODE GRID (via HasBandModeGrid trait)
    // ========================================================================

    protected function bandModeGridQuery(): Builder
    {
        return Contact::where('event_configuration_id', $this->config()->id)
            ->notDuplicate();
    }

    // ========================================================================
    // BONUS LIST
    // ========================================================================

    #[Computed]
    public function bonusList(): array
    {
        $eventTypeId = $this->event?->event_type_id;

        $classCode = $this->config()?->operatingClass?->code;

        $rulesVersion = $this->event?->resolved_rules_version;

        $query = BonusType::where('is_active', true);
        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }
        if ($rulesVersion !== null) {
            $query->where('rules_version', $rulesVersion);
        }
        $bonusTypes = $query->orderByDesc('objective_multiplier')->orderByDesc('base_points')->get()
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

            $eventBonus = $claimedBonuses->get($bonusType->id)?->setRelation('bonusType', $bonusType);
            $list[] = $this->buildBonusEntry($bonusType, $eventBonus);
        }

        return $this->withImpliedObjectivesVerified($list);
    }

    /**
     * Mark objectives the rulebook implies from another verified objective as verified.
     *
     * Keeps the checklist and its summed multiplier in step with the score,
     * which already counts the implied objective (e.g. WFD 2027's 100%
     * alternative power earning the station-equipment objective).
     *
     * @param  array<int, array{type: BonusType, bonus: mixed, status: string, points: int}>  $list
     * @return array<int, array{type: BonusType, bonus: mixed, status: string, points: int}>
     */
    private function withImpliedObjectivesVerified(array $list): array
    {
        $rules = $this->event ? app(RuleSetFactory::class)->forEvent($this->event) : null;

        if (! $rules instanceof ImpliesObjectives) {
            return $list;
        }

        $verifiedCodes = collect($list)
            ->where('status', 'verified')
            ->map(fn (array $item) => $item['type']->code);

        $impliedCodes = collect($rules->impliedObjectives())
            ->only($verifiedCodes->all())
            ->flatten()
            ->all();

        foreach ($list as $index => $item) {
            if ($item['status'] !== 'verified' && in_array($item['type']->code, $impliedCodes, true)) {
                $list[$index]['status'] = 'verified';
            }
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
            $points = $eventBonus->claimedPoints();
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
     * @return array{verified_pts: int, claimed_pts: int, verified_count: int, claimed_count: int, unclaimed_count: int, verified_multiplier: int, claimed_multiplier: int}
     */
    #[Computed]
    public function bonusSummary(): array
    {
        $list = collect($this->bonusList);

        return [
            'verified_pts' => (int) $list->where('status', 'verified')->sum('points'),
            'claimed_pts' => (int) $list->where('status', 'claimed')->sum('points'),
            'verified_count' => $list->where('status', 'verified')->count(),
            'claimed_count' => $list->where('status', 'claimed')->count(),
            'unclaimed_count' => $list->where('status', 'unclaimed')->count(),
            // Objective rulebooks award multipliers rather than points, so the
            // same tiles show summed OM instead of summed points.
            'verified_multiplier' => (int) $list->where('status', 'verified')
                ->sum(fn (array $item) => (int) $item['type']->objective_multiplier),
            'claimed_multiplier' => (int) $list->where('status', 'claimed')
                ->sum(fn (array $item) => (int) $item['type']->objective_multiplier),
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

        $multiplier = $this->powerMultiplier;

        if ($multiplier === 1) {
            return "Operating at {$effectiveWatts}W (over 100W) gives a 1\u{00d7} multiplier.{$stationNote}";
        }

        if ($multiplier === 5) {
            return "At {$effectiveWatts}W on natural power only, the event qualifies for a 5\u{00d7} multiplier.";
        }

        if ($effectiveWatts > 5) {
            return "Operating at {$effectiveWatts}W (over 5W, up to 100W) gives a 2\u{00d7} multiplier.{$stationNote}";
        }

        // QRP (5W or less) but not 5x: explain which power setting rules it out.
        $hasNaturalPower = $config->uses_battery || $config->uses_solar || $config->uses_wind || $config->uses_water;
        $hasDisqualifyingPower = $config->uses_commercial_power || $config->uses_generator;

        return match (true) {
            $hasDisqualifyingPower => "Operating at {$effectiveWatts}W (QRP) gives a 2\u{00d7} multiplier. Switch to natural power only to qualify for 5\u{00d7}.",
            $hasNaturalPower => "Operating at {$effectiveWatts}W (QRP) gives a 2\u{00d7} multiplier. A station is set to commercial or generator power, so the event doesn't qualify for 5\u{00d7}.",
            default => "Operating at {$effectiveWatts}W (QRP) gives a 2\u{00d7} multiplier.",
        };
    }

    // ========================================================================
    // POWER MULTIPLIER RULES TABLE
    // ========================================================================

    /**
     * Rules table for the power multiplier, with the active row flagged.
     * Depends on the powerMultiplier computed property, and uses the same
     * effective power (highest of the event and its stations) that drives it.
     *
     * @return array<int, array{condition: string, multiplier: string, active: bool}>
     */
    #[Computed]
    public function powerMultiplierRules(): array
    {
        $config = $this->config();
        $watts = $config?->effectiveMaxPowerWatts() ?? 0;
        $multi = $this->powerMultiplier;

        return [
            [
                'condition' => '≤ 5W, natural power at event and stations only',
                'multiplier' => '5×',
                'active' => $multi === 5,
            ],
            [
                'condition' => '≤ 5W, other power settings',
                'multiplier' => '2×',
                'active' => $multi === 2 && $watts <= 5,
            ],
            [
                'condition' => 'Over 5W, up to 100W (any power source)',
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
                'message' => "High dupe rate ({$rate}%). Review the log for callsign entry errors.",
            ];
        }

        // Zero-point contacts error
        $zeroCount = $this->zeroPointContactCount;
        if ($zeroCount > 0) {
            $notices[] = [
                'severity' => 'error',
                'section' => 'qso',
                'message' => "{$zeroCount} ".str('contact')->plural($zeroCount).' logged with 0 points. Check their band and mode.',
            ];
        }

        // GOTA contacts without GOTA station
        $gotaCount = $this->gotaContactCount;
        if ($gotaCount > 0 && ! $this->config()->has_gota_station) {
            $notices[] = [
                'severity' => 'error',
                'section' => 'qso',
                'message' => "{$gotaCount} GOTA ".str('contact')->plural($gotaCount).' logged, but no GOTA station is configured.',
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
                'message' => "{$unverifiedCount} bonus ".str('claim')->plural($unverifiedCount).' not yet verified.',
            ];
        }

        // QRP power opportunity: <=5W but only 2x because no natural power
        $watts = $this->config()->effectiveMaxPowerWatts();
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
                    'message' => "For the 5\u{00d7} multiplier, all stations must use 5W or less and natural power only (battery, solar, wind, or water). Commercial and generator power disqualify the event.",
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
        $all = Band::allowedForFieldDay()->ordered()->get();

        if (! $this->config()) {
            return $all;
        }

        $bandIdsWithQsos = Contact::where('event_configuration_id', $this->config()->id)
            ->notDuplicate()
            ->distinct()
            ->pluck('band_id')
            ->all();

        return $all->filter(fn (Band $band) => ! $band->isShownOnlyWithContacts() || in_array($band->id, $bandIdsWithQsos))
            ->values();
    }

    /**
     * Points a single contact in this mode is worth under the event's ruleset.
     *
     * Field Day reads modes.points_fd and Winter Field Day modes.points_wfd, so
     * the scoring key asks the ruleset rather than picking a column.
     */
    public function pointsForMode(Mode $mode): int
    {
        $config = $this->config();

        if (! $config) {
            return (int) ($mode->points_fd ?? 1);
        }

        return $config->pointsForContact($mode, Station::make(['is_gota' => false]));
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
