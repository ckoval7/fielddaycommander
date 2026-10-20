<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Rules;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Bonuses\WinterFieldDay2026\AlternativePowerStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\AwayFromHomeStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\FmSatelliteStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\MultipleAntennasStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\MultipleModesStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\QrpStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\SixBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\SixContinuousHoursStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\SpecialBulletinStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\SsbCwSatelliteStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\TwelveBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\WinlinkEmailStrategy;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\DomainEvents\W1awBulletinChanged;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;
use App\Scoring\Dto\ScoreLine;
use App\Scoring\Dto\ScoreTerm;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Winter Field Day Association 2026 rules.
 *
 * FROZEN. Do not modify this file after merge. Any WFDA 2027 rule change goes
 * into a new WinterFieldDay2027 class — see docs/scoring/adding-a-rules-version.md.
 *
 * Source: WFDA Standard Operating Procedure, https://winterfieldday.org/sop.php
 * (2026 edition).
 *
 * This is deliberately NOT a {@see FieldDayRuleSet}.
 * WFD scores on a different model from ARRL Field Day:
 *
 *  - Score is `total QSO points x (OM + 1)`, not points plus additive bonuses.
 *  - Awards are "objectives", each worth an Objective Multiplier, not points.
 *  - There is no power multiplier; all stations are capped at 100 W PEP and
 *    running QRP is itself an objective.
 *  - There is no GOTA station, and no youth or emergency-power bonus.
 */
class WinterFieldDay2026 implements RuleSet
{
    /**
     * Phone is 1 point; CW and Digital are 2 points each (SOP, "QSO Points").
     * Used only when a mode has no `points_wfd` value of its own.
     */
    protected const FALLBACK_POINTS = 1;

    /**
     * Rulebook section heading shared by every objective and multiplier entry.
     */
    protected const SECTION_OBJECTIVE_MULTIPLIERS = 'Objective & Multipliers';

    protected ?int $cachedEventTypeId = null;

    /** @var array<string, ?BonusType> */
    protected array $cachedBonuses = [];

    public function id(): string
    {
        return 'WFD-2026';
    }

    public function version(): string
    {
        return '2026';
    }

    public function eventTypeCode(): string
    {
        return 'WFD';
    }

    /**
     * Per-contact QSO points: Phone 1, CW 2, Digital 2.
     *
     * Satellite QSOs earn no QSO credit under the WFD rules, but that is a
     * per-contact property rather than a per-mode one, so it is applied when
     * the contacts are totalled in {@see qsoPoints()} rather than here.
     */
    public function pointsForContact(Mode $mode, Station $station): int
    {
        $override = ModeRulePoint::query()
            ->whereHas('eventType', fn ($q) => $q->where('code', $this->eventTypeCode()))
            ->where('rules_version', $this->version())
            ->where('mode_id', $mode->id)
            ->value('points');

        return (int) ($override ?? $mode->points_wfd ?? self::FALLBACK_POINTS);
    }

    /**
     * Total QSO points: every non-duplicate contact except satellite QSOs.
     *
     * SOP: "Satellite contacts do not count towards your total QSO points.
     * Only the multiplier applies."
     */
    public function qsoPoints(EventConfiguration $config): int
    {
        return (int) $config->contacts()
            ->where('is_duplicate', false)
            ->where('is_satellite', false)
            ->sum('points');
    }

    /**
     * Sum of the Objective Multipliers on every objective achieved.
     *
     * Achievement is the presence of a verified event_bonuses row, whether it
     * was written by a strategy or claimed by hand.
     */
    public function objectiveMultiplier(EventConfiguration $config): int
    {
        return (int) $this->achievedObjectives($config)
            ->with('bonusType')
            ->get()
            ->sum(fn ($bonus) => (int) $bonus->bonusType?->objective_multiplier);
    }

    /**
     * Verified claims on this version's active objectives.
     *
     * @return HasMany<EventBonus, EventConfiguration>
     */
    protected function achievedObjectives(EventConfiguration $config)
    {
        return $config->bonuses()
            ->where('is_verified', true)
            ->whereHas('bonusType', fn ($q) => $q->where('rules_version', $this->version())
                ->whereNotNull('objective_multiplier')
                ->where('is_active', true));
    }

    /**
     * How many of this version's objectives have been achieved, and how many exist.
     *
     * WFDA tracks completion percentage year over year, so the UI surfaces it
     * alongside the score.
     *
     * @return array{achieved: int, available: int}
     */
    public function objectiveProgress(EventConfiguration $config): array
    {
        $eventTypeId = $this->eventTypeId();

        $available = $eventTypeId
            ? BonusType::query()
                ->where('event_type_id', $eventTypeId)
                ->where('rules_version', $this->version())
                ->whereNotNull('objective_multiplier')
                ->where('is_active', true)
                ->count()
            : 0;

        $achieved = $this->achievedObjectives($config)->count();

        return ['achieved' => $achieved, 'available' => $available];
    }

    /**
     * WFD score: total QSO points x (OM + 1).
     *
     * The +1 is in the published formula so that a station completing no
     * objectives still scores its QSO points rather than zero.
     */
    public function score(EventConfiguration $config): ScoreBreakdown
    {
        $qsoPoints = $this->qsoPoints($config);
        $objectiveMultiplier = $this->objectiveMultiplier($config);
        $multiplier = $objectiveMultiplier + 1;
        $progress = $this->objectiveProgress($config);

        return new ScoreBreakdown(
            total: $qsoPoints * $multiplier,
            lines: [
                new ScoreLine('qso_points', 'QSO Points', $qsoPoints, 'Satellite QSOs excluded'),
                new ScoreLine(
                    'objectives_achieved',
                    'Objectives Achieved',
                    $progress['achieved'],
                    "of {$progress['available']}",
                ),
                new ScoreLine('objectives_available', 'Objectives Available', $progress['available']),
                new ScoreLine('objective_multiplier', 'Objective Multiplier (OM)', $objectiveMultiplier),
                new ScoreLine('total_multiplier', 'Score Multiplier (OM + 1)', $multiplier),
            ],
            formula: 'QSO Points x (OM + 1)',
            headline: [
                new ScoreTerm('QSO Points', number_format($qsoPoints), '#col-qso'),
                '×',
                new ScoreTerm('OM + 1', $multiplier.'×', '#col-bonus'),
            ],
        );
    }

    public function isScored(): bool
    {
        return true;
    }

    public function nomenclature(): Nomenclature
    {
        return new Nomenclature(
            awardSingular: 'Objective',
            awardPlural: 'Objectives',
            awardSectionTitle: 'Objectives',
            claimsTitle: 'Manual Objective Claims',
            awardValueLabel: 'Multiplier',
            awardsAreMultipliers: true,
            rulebookName: 'WFDA',
            bulletinName: 'WFD Special Bulletin',
            bulletinSources: [],
            bulletinCapturedLabel: 'Objective Earned: OM x1',
        );
    }

    public function cabrilloContestName(): string
    {
        return 'WFD';
    }

    public function logFilenameSlug(): string
    {
        return 'winter-field-day';
    }

    /**
     * Strategy classes indexed by the domain event class they subscribe to.
     *
     * Mirror of the `strategies()` array — keep these two in sync when adding
     * a new strategy. Looked up on every domain event dispatch, so keep it O(1).
     *
     * @var array<class-string, array<int, class-string<BonusStrategy>>>
     */
    protected const STRATEGY_INDEX = [
        QsoLogged::class => [
            SixBandsStrategy::class,
            TwelveBandsStrategy::class,
            MultipleModesStrategy::class,
            QrpStrategy::class,
            AlternativePowerStrategy::class,
        ],
        W1awBulletinChanged::class => [
            SpecialBulletinStrategy::class,
        ],
    ];

    public function strategiesFor(string $eventClass): array
    {
        return self::STRATEGY_INDEX[$eventClass] ?? [];
    }

    public function strategies(): array
    {
        return [
            'alternative_power' => AlternativePowerStrategy::class,
            'away_from_home' => AwayFromHomeStrategy::class,
            'multiple_antennas' => MultipleAntennasStrategy::class,
            'fm_satellite' => FmSatelliteStrategy::class,
            'ssb_cw_satellite' => SsbCwSatelliteStrategy::class,
            'winlink_email' => WinlinkEmailStrategy::class,
            'special_bulletin' => SpecialBulletinStrategy::class,
            'six_bands' => SixBandsStrategy::class,
            'twelve_bands' => TwelveBandsStrategy::class,
            'multiple_modes' => MultipleModesStrategy::class,
            'qrp' => QrpStrategy::class,
            'six_continuous_hours' => SixContinuousHoursStrategy::class,
        ];
    }

    /**
     * WFDA 2026 SOP — objective text, verbatim.
     *
     * The SOP does not number its objectives, so the section label is the
     * document heading the text appears under.
     *
     * @return array<string, array{section: string, text: string}>
     */
    protected function ruleReferences(): array
    {
        return [
            'alternative_power' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate 100% on alternative power: Operate exclusively on alternative power, defined as any power source not connected to the commercial power grid. You may use generators, batteries, solar power, wind power, or anything else. All batteries, whether in use or charging, should only be recharged using alternative power. WFD stations should run all station equipment, including all laptops and other accessories, from an alternative power source. Lights and HVAC are exceptions and may be connected to the power grid or any power source available. OM x1',
            ],
            'away_from_home' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate away from home: Operating away from home is one of the main reasons for “Winter Field Day.” Do you have the ability to walk into any shelter, parking garage, hospital, or community center and set up a portable Amateur radio station? Now is the time to start planning what you will do if your home location is destroyed during an emergency. For this objective, set up your field station more than ½ mile from your home. OM x3',
            ],
            'multiple_antennas' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Deploy and make at least one contact on multiple antennas: Deploy two or more antennas that have not been previously installed and make at least one contact on each. Previously installed antennas are any antennas that were deployed or installed before the WFD set-up time. Previously installed antennas do not count. You must deploy the antennas during the WFD set-up time or event to achieve this OM. This could be a dipole and a hex beam or an EFHW and a 2-meter J-pole. Any combination of antennas works. Multi-band antennas do not count as separate antennas. OM x1',
            ],
            'fm_satellite' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make an FM satellite contact: Make at least 1 FM satellite contact during the operating period. Dedicated satellite transmitters do not count toward your Category number. Satellite contacts do not count towards your total QSO points. Only the multiplier applies. See the appendix below for more information on satellite contacts. OM x2',
            ],
            'ssb_cw_satellite' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make a SSB or CW satellite contact: Make at least one contact using SSB or CW. Dedicated satellite transmitters do not count toward your Category number. Satellite contacts do not count towards your total QSO points. Only the multiplier applies. See the appendix below for more information on satellite contacts. OM x3',
            ],
            'winlink_email' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Send and receive at least one Winlink email: Winlink has proven useful during emergencies and is considered a digital mode. Successfully send and receive at least one email from a winlink.org email address to any Winlink or commercial email address via amateur RF. All time stamps on Winlink contacts must fall within the operational period. OM x1',
            ],
            'special_bulletin' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Copy the Winter Field Day Special Bulletin: Accurately copy the WFD Special Bulletin message and submit your copy with your log submission to achieve this objective. The frequencies and times are published on our website prior to the event. OM x1',
            ],
            'six_bands' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make three contacts on at least six (6) different bands: Conditions may change throughout an event. Log operations on at least six different bands by making a minimum of three contacts per band. You should be able to accomplish this objective by utilizing HF, VHF, and UHF frequencies. Don’t forget about 1.25 meters (220)! It’s an excellent band for local emergencies. OM x6',
            ],
            'twelve_bands' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make three contacts on at least twelve (12) different bands: Was six too easy? You may have to pull out your microwave equipment to achieve this one. Log operations on at least twelve different bands by making a minimum of three contacts per band. The six bands from the previous objective count toward this one. OM x6',
            ],
            'multiple_modes' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Use multiple modes: Increase your versatility by using multiple modes during the event, such as Phone and CW, CW and Digital, or Phone and Digital. Using all three modes does not increase this OM. OM x2',
            ],
            'qrp' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate the event QRP: Operating on QRP means every station in your Winter Field Day operation is using 10 watts or less on Phone or 5 watts or less on CW or Digital for the entire time you choose to operate during the event. OM x4',
            ],
            'six_continuous_hours' => [
                'section' => self::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate six continuous hours during the event: Emergencies may last days or even weeks. You may be expected to man a radio station between 4-12 hours if you are operating alone or in shifts. Can you sit and operate for extended periods of time with enough backup power? This does not necessarily mean you are making contacts the whole time, but you are in front of the radio, monitoring, and ready to pick up a microphone if you are called. OM x2',
            ],
        ];
    }

    public function bonusRuleReference(string $code): ?array
    {
        return $this->ruleReferences()[$code] ?? null;
    }

    public function bonus(string $code): ?BonusType
    {
        if (array_key_exists($code, $this->cachedBonuses)) {
            return $this->cachedBonuses[$code];
        }

        $eventTypeId = $this->eventTypeId();

        if (! $eventTypeId) {
            return $this->cachedBonuses[$code] = null;
        }

        return $this->cachedBonuses[$code] = BonusType::query()
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', $this->version())
            ->where('code', $code)
            ->first();
    }

    protected function eventTypeId(): ?int
    {
        return $this->cachedEventTypeId ??= EventType::query()
            ->where('code', $this->eventTypeCode())
            ->value('id');
    }
}
