<?php

namespace App\Scoring\Rules;

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Bonuses\WinterFieldDay2027\AlternativePowerEquipmentStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\AlternativePowerFullStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\AwayFromHomeStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\BulletinStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\CrossbandRepeaterStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\MultipleAntennasStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\MultipleModesStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\QrpStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\SixBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\SixContinuousHoursStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\SstvImageStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\TwelveBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\WinlinkEmailStrategy;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\DeterminesContactCredit;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\ImpliesObjectives;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;
use App\Scoring\Dto\ScoreLine;
use App\Scoring\Dto\ScoreTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Winter Field Day Association 2027 rules.
 *
 * FROZEN once shipped. Any WFDA 2028 rule change goes into a new class — see
 * docs/scoring/adding-a-rules-version.md.
 *
 * Source: WFDA "Winter Field Day 2027" rules, Version 3, 04 OCT 2026
 * (https://winterfieldday.org).
 *
 * Deliberately standalone rather than a subclass of {@see WinterFieldDay2026}:
 * 2026 resolves its strategy index through `self::`, so overrides would not be
 * picked up, and inheriting from a frozen year would let an accidental edit
 * there silently change 2027 scores. Like 2026, this is NOT a
 * {@see FieldDayRuleSet}:
 *
 *  - Score is `total QSO points x (OM + 1)`, not points plus additive bonuses.
 *  - Awards are "objectives", each worth an Objective Multiplier, not points.
 *  - There is no power multiplier; all stations are capped at 100 W PEP and
 *    running QRP is itself an objective.
 *  - There is no GOTA station, and no youth or emergency-power bonus.
 *
 * Changes from 2026: the satellite objectives are gone; alternative power is
 * split into a station-equipment objective (OM x1) and a 100% objective (OM x2)
 * that also qualifies for the equipment one; SSTV image and cross-band repeater
 * objectives are new; the Special Bulletin is now just the Bulletin. WSJT
 * modes are no longer permitted, and satellite contacts no longer earn credit;
 * both are still logged but count toward nothing ({@see noCreditReason()}).
 */
class WinterFieldDay2027 implements DeterminesContactCredit, ImpliesObjectives, RuleSet
{
    /**
     * "All modes, except WSJT modes, may be used." Footnote: "WSJT modes
     * include: FT2, FST4, FT4, FT8, JT4, JT9, JT65, Q65, MSK144, WSPR, FST4W,
     * and Echo." Matched against the contact's sub-mode.
     *
     * @var array<int, string>
     */
    protected const WSJT_SUBMODES = [
        'FT2', 'FST4', 'FT4', 'FT8', 'JT4', 'JT9', 'JT65', 'Q65',
        'MSK144', 'WSPR', 'FST4W', 'ECHO',
    ];

    /**
     * Sub-mode prefixes whose speed or tone-spacing variants (JT9-1, JT65A,
     * Q65-30A, FST4-60 ...) are the same banned WSJT mode family.
     *
     * @var array<int, string>
     */
    protected const WSJT_SUBMODE_PREFIXES = ['JT4', 'JT9', 'JT65', 'Q65', 'FST4'];

    public const NO_CREDIT_WSJT = 'WSJT modes are not permitted in WFD 2027';

    public const NO_CREDIT_SATELLITE = 'Satellite (relayed, cross-band) contacts earn no WFD credit';

    /**
     * Phone is 1 point; CW and Digital are 2 points each ("QSO Points").
     * Used only when a mode has no `points_wfd` value of its own.
     */
    protected const FALLBACK_POINTS = 1;

    /**
     * Rulebook section heading shared by every objective and multiplier entry.
     */
    protected const SECTION_OBJECTIVE_MULTIPLIERS = 'Objective & Multipliers';

    /**
     * "Achieving this objective qualifies you for the 'Operate station
     * equipment on alternative power' objective."
     *
     * @var array<string, array<int, string>>
     */
    protected const IMPLIED_OBJECTIVES = [
        'alt_power_full' => ['alt_power_equipment'],
    ];

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
        ],
    ];

    protected ?int $cachedEventTypeId = null;

    /** @var array<string, ?BonusType> */
    protected array $cachedBonuses = [];

    public function id(): string
    {
        return 'WFD-2027';
    }

    public function version(): string
    {
        return '2027';
    }

    public function eventTypeCode(): string
    {
        return 'WFD';
    }

    /**
     * Per-contact QSO points: Phone 1, CW 2, Digital 2.
     *
     * Whether a contact earns QSO credit at all is a per-contact property, so
     * it is applied when contacts are totalled in {@see qsoPoints()}.
     */
    public function pointsForContact(Mode $mode, Station $station): int
    {
        $override = ModeRulePoint::query()
            ->whereHas('eventType', fn ($q) => $q->where('code', $this->eventTypeCode()))
            ->where('rules_version', $this->version())
            ->where('mode_id', $mode->id)
            ->value('points');

        return (int) ($override ?? $mode->points_wfd ?? static::FALLBACK_POINTS);
    }

    /**
     * Total QSO points over every contact that earns QSO credit.
     */
    public function qsoPoints(EventConfiguration $config): int
    {
        return (int) $this->creditableContacts($config)->sum('points');
    }

    /**
     * Non-duplicate contacts that earn credit under these rules.
     *
     * @return HasMany<Contact, EventConfiguration>
     */
    protected function creditableContacts(EventConfiguration $config): HasMany
    {
        $query = $config->contacts()->where('is_duplicate', false);

        $this->constrainToCreditable($query);

        return $query;
    }

    /**
     * Non-duplicate contacts logged but earning no credit (WSJT mode, satellite).
     */
    protected function noCreditContactCount(EventConfiguration $config): int
    {
        return $config->contacts()->where('is_duplicate', false)->count()
            - $this->creditableContacts($config)->count();
    }

    /**
     * WSJT-mode and satellite contacts earn no credit.
     *
     * "All modes, except WSJT modes, may be used." And: "You may only claim
     * credit for contacts that are on one band, one mode, simplex, and made
     * directly to the receiving station." A satellite contact is cross-band
     * and relayed.
     */
    public function noCreditReason(Contact $contact): ?string
    {
        if ($this->isWsjtSubmode($contact->submode)) {
            return static::NO_CREDIT_WSJT;
        }

        if ($contact->is_satellite || ($contact->band_id !== null && $contact->band?->is_satellite)) {
            return static::NO_CREDIT_SATELLITE;
        }

        return null;
    }

    /**
     * SQL form of {@see noCreditReason()}. Null sub-modes and bands are creditable.
     */
    public function constrainToCreditable(Builder|HasMany $query): void
    {
        $query
            ->where(fn ($q) => $q->whereNull('submode')->orWhereNot(function ($wsjt) {
                $wsjt->whereIn('submode', static::WSJT_SUBMODES);

                foreach (static::WSJT_SUBMODE_PREFIXES as $prefix) {
                    $wsjt->orWhere('submode', 'like', $prefix.'%');
                }
            }))
            ->where('is_satellite', false)
            ->where(fn ($q) => $q->whereNull('band_id')
                ->orWhereNotIn('band_id', Band::query()->where('is_satellite', true)->select('id')));
    }

    /**
     * Whether a (stored, upper-cased) sub-mode is one of the banned WSJT modes.
     */
    protected function isWsjtSubmode(?string $submode): bool
    {
        $submode = mb_strtoupper((string) $submode);

        if ($submode === '') {
            return false;
        }

        return in_array($submode, static::WSJT_SUBMODES, true)
            || collect(static::WSJT_SUBMODE_PREFIXES)
                ->contains(fn (string $prefix): bool => str_starts_with($submode, $prefix));
    }

    /**
     * Sum of the Objective Multipliers on every objective achieved.
     *
     * An objective implied by another achieved objective counts once, whether
     * or not it was also claimed in its own right.
     */
    public function objectiveMultiplier(EventConfiguration $config): int
    {
        return (int) $this->achievedObjectiveTypes($config)
            ->sum(fn (BonusType $type) => (int) $type->objective_multiplier);
    }

    /**
     * How many of this version's objectives have been achieved, and how many exist.
     *
     * @return array{achieved: int, available: int}
     */
    public function objectiveProgress(EventConfiguration $config): array
    {
        return [
            'achieved' => $this->achievedObjectiveTypes($config)->count(),
            'available' => $this->activeObjectives()->count(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function impliedObjectives(): array
    {
        return static::IMPLIED_OBJECTIVES;
    }

    /**
     * Every achieved objective's bonus type, keyed by code, implications applied.
     *
     * Achievement is the presence of a verified event_bonuses row, whether it
     * was written by a strategy or claimed by hand, plus any objective that an
     * achieved one implies.
     *
     * @return Collection<string, BonusType>
     */
    protected function achievedObjectiveTypes(EventConfiguration $config): Collection
    {
        $achieved = $this->achievedObjectives($config)
            ->with('bonusType')
            ->get()
            ->map(fn (EventBonus $bonus) => $bonus->bonusType)
            ->filter()
            ->keyBy('code');

        $impliedCodes = collect($this->impliedObjectives())
            ->only($achieved->keys())
            ->flatten()
            ->reject(fn (string $code) => $achieved->has($code))
            ->unique();

        if ($impliedCodes->isEmpty()) {
            return $achieved;
        }

        return $achieved->merge(
            $this->activeObjectives()->whereIn('code', $impliedCodes->all())->get()->keyBy('code'),
        );
    }

    /**
     * Verified claims on this version's active objectives.
     *
     * @return HasMany<EventBonus, EventConfiguration>
     */
    protected function achievedObjectives(EventConfiguration $config): HasMany
    {
        return $config->bonuses()
            ->where('is_verified', true)
            ->whereHas('bonusType', fn ($q) => $q->where('rules_version', $this->version())
                ->whereNotNull('objective_multiplier')
                ->where('is_active', true));
    }

    /**
     * This version's active objective rows.
     *
     * @return Builder<BonusType>
     */
    protected function activeObjectives(): Builder
    {
        return BonusType::query()
            ->where('event_type_id', $this->eventTypeId())
            ->where('rules_version', $this->version())
            ->whereNotNull('objective_multiplier')
            ->where('is_active', true);
    }

    /**
     * WFD score: total QSO points x (OM + 1).
     *
     * "We will add 1 to your objective multiplier, so you still get your QSO
     * points if you do not complete any objectives."
     */
    public function score(EventConfiguration $config): ScoreBreakdown
    {
        $qsoPoints = $this->qsoPoints($config);
        $objectiveMultiplier = $this->objectiveMultiplier($config);
        $multiplier = $objectiveMultiplier + 1;
        $progress = $this->objectiveProgress($config);
        $noCreditContacts = $this->noCreditContactCount($config);

        return new ScoreBreakdown(
            total: $qsoPoints * $multiplier,
            lines: [
                new ScoreLine('qso_points', 'QSO Points', $qsoPoints),
                new ScoreLine(
                    'no_credit_contacts',
                    'No-Credit Contacts',
                    $noCreditContacts,
                    'WSJT-mode and satellite contacts, logged but not counted',
                ),
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

    public function strategiesFor(string $eventClass): array
    {
        return static::STRATEGY_INDEX[$eventClass] ?? [];
    }

    public function strategies(): array
    {
        return [
            'alt_power_equipment' => AlternativePowerEquipmentStrategy::class,
            'alt_power_full' => AlternativePowerFullStrategy::class,
            'away_from_home' => AwayFromHomeStrategy::class,
            'multiple_antennas' => MultipleAntennasStrategy::class,
            'sstv_image' => SstvImageStrategy::class,
            'crossband_repeater' => CrossbandRepeaterStrategy::class,
            'winlink_email' => WinlinkEmailStrategy::class,
            'bulletin' => BulletinStrategy::class,
            'six_bands' => SixBandsStrategy::class,
            'twelve_bands' => TwelveBandsStrategy::class,
            'multiple_modes' => MultipleModesStrategy::class,
            'qrp' => QrpStrategy::class,
            'six_continuous_hours' => SixContinuousHoursStrategy::class,
        ];
    }

    /**
     * WFDA 2027 rules — objective text, verbatim.
     *
     * The rules do not number their objectives, so the section label is the
     * document heading the text appears under.
     *
     * @return array<string, array{section: string, text: string}>
     */
    protected function ruleReferences(): array
    {
        return [
            'alt_power_equipment' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate station equipment on alternative power: Operate all station equipment, including all laptops, networking components, and other accessories, from an alternative power source for the entirety of the operating period. OMx1',
            ],
            'alt_power_full' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate 100% on alternative power: Operate exclusively on alternative power, defined as any power source not connected to the commercial power grid. You may use generators, batteries, solar power, wind power, or any other alternative power source. All batteries, whether in use or charging, may only be recharged using alternative power. All station equipment, lights, HVAC, and other appliances used to sustain operators must be on alternative power for the entire operating period. Achieving this objective qualifies you for the “Operate station equipment on alternative power” objective. OM x2',
            ],
            'away_from_home' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate away from home: Can you walk into a shelter, parking garage, hospital, or community center and set up a portable Amateur radio station? Now is the time to start planning what you will do if your home location is destroyed during an emergency. For this objective, set up your field station more than ½ mile from your home. OM x3',
            ],
            'multiple_antennas' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Deploy and make at least one contact on multiple antennas: Deploy two or more antennas that have not been previously installed and make at least one contact on each. Previously installed antennas are any antennas that were deployed or installed before the WFD set-up time. Previously installed antennas do not count. You must deploy the antennas during the WFD set-up time or event to achieve this OM. This could be a dipole and a hex beam or an EFHW and a 2-meter J-pole. Any combination of antennas works. Multi-band antennas do not count as separate antennas. OM x1',
            ],
            'sstv_image' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Send and receive the WFD SSTV image: Send and receive the official WFD Slow Scan Television (SSTV) image in a WFD QSO. To complete this objective, you must send and receive the image and WFD exchange with any station and log it. This demonstrates your ability to operate beyond voice and data modes by showing proficiency with image operations. Look for the image on our website soon. OM x2',
            ],
            'crossband_repeater' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Set up and make at least three contacts on a cross-band repeater: Cross-band repeaters help extend communications in an emergency. Set one up that operates on any two bands (think 2m and 70cm for many mobile transceivers) and make three contacts. Repeaters set up prior to the WFD setup period are not eligible. The transmission must leave your WFD site. You cannot contact anyone who has operated at your site. Do not log these contacts for WFD. These contacts do not count for QSO credit. OM x3',
            ],
            'winlink_email' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Send and receive at least one Winlink email: Winlink has proven useful during emergencies. Send and receive at least one email from a winlink.org email address to any other email address via amateur RF. All email exchanges must occur within the operational period. These contacts do not count for QSO credit unless you comply with the QSO Credit paragraph above. OM x1',
            ],
            'bulletin' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Copy the Winter Field Day Bulletin: Accurately copy the WFD Bulletin and submit your copy with your log submission to achieve this objective. We publish frequencies and times on our website prior to the event. OM x1',
            ],
            'six_bands' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make three contacts on at least six (6) different bands: Conditions may change throughout an event. Log at least three QSOs on at least six different bands. You can use any authorized band, not just HF. Don’t forget about 1.25 meters (220)! It’s an excellent band for local emergencies. OM x6',
            ],
            'twelve_bands' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Make three contacts on at least twelve (12) different bands: Was six too easy? You may have to pull out your microwave equipment to achieve this one. Log at least three QSOs on at least twelve different bands. The six bands from the previous objective count toward this one. OM x6',
            ],
            'multiple_modes' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Use multiple modes: Increase your versatility by completing at least one QSO on multiple modes during the event, such as Phone and CW, CW and Digital, or Phone and Digital. Making a QSO on all three modes does not increase this OM. OM x2',
            ],
            'qrp' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
                'text' => 'Operate the event QRP: Operating on QRP means every station in your Winter Field Day operation is using 10 watts or less on Phone or 5 watts or less on CW or Digital for the entire time you choose to operate during the event. OM x4',
            ],
            'six_continuous_hours' => [
                'section' => static::SECTION_OBJECTIVE_MULTIPLIERS,
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
