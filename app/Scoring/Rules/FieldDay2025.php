<?php

namespace App\Scoring\Rules;

use App\Models\BonusType;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Bonuses\FieldDay2025\AgencyVisitStrategy;
use App\Scoring\Bonuses\FieldDay2025\EducationalActivityStrategy;
use App\Scoring\Bonuses\FieldDay2025\ElectedOfficialVisitStrategy;
use App\Scoring\Bonuses\FieldDay2025\MediaPublicityStrategy;
use App\Scoring\Bonuses\FieldDay2025\NtsMessageStrategy;
use App\Scoring\Bonuses\FieldDay2025\PublicInfoBoothStrategy;
use App\Scoring\Bonuses\FieldDay2025\PublicLocationStrategy;
use App\Scoring\Bonuses\FieldDay2025\SmSecMessageStrategy;
use App\Scoring\Bonuses\FieldDay2025\SocialMediaStrategy;
use App\Scoring\Bonuses\FieldDay2025\W1awBulletinStrategy;
use App\Scoring\Bonuses\FieldDay2025\WebSubmissionStrategy;
use App\Scoring\Bonuses\FieldDay2025\YouthParticipationStrategy;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use App\Scoring\DomainEvents\MessageChanged;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\DomainEvents\W1awBulletinChanged;
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
            YouthParticipationStrategy::class,
        ],
        MessageChanged::class => [
            NtsMessageStrategy::class,
            SmSecMessageStrategy::class,
        ],
        GuestbookEntryChanged::class => [
            AgencyVisitStrategy::class,
            ElectedOfficialVisitStrategy::class,
        ],
        W1awBulletinChanged::class => [
            W1awBulletinStrategy::class,
        ],
    ];

    public function strategiesFor(string $eventClass): array
    {
        return self::STRATEGY_INDEX[$eventClass] ?? [];
    }

    public function strategies(): array
    {
        return [
            'sm_sec_message' => SmSecMessageStrategy::class,
            'nts_message' => NtsMessageStrategy::class,
            'w1aw_bulletin' => W1awBulletinStrategy::class,
            'elected_official_visit' => ElectedOfficialVisitStrategy::class,
            'agency_visit' => AgencyVisitStrategy::class,
            'media_publicity' => MediaPublicityStrategy::class,
            'youth_participation' => YouthParticipationStrategy::class,
            'social_media' => SocialMediaStrategy::class,
            'public_location' => PublicLocationStrategy::class,
            'public_info_booth' => PublicInfoBoothStrategy::class,
            'educational_activity' => EducationalActivityStrategy::class,
            'web_submission' => WebSubmissionStrategy::class,
        ];
    }

    /**
     * ARRL Field Day 2025 Rules — bonus section text.
     * Source: Field-Day-Rules.pdf, Revised 3/2025.
     *
     * @return array<string, array{section: string, text: string}>
     */
    protected function ruleReferences(): array
    {
        return [
            'emergency_power' => [
                'section' => '7.3.1',
                'text' => '100% Emergency Power: 100 points per transmitter classification if all contacts are made only using an emergency power source up to a total of 20 transmitters (maximum 2,000 points). GOTA station and free VHF Station for Class A and F entries do not qualify for bonus point credit and should not be included in the club\'s transmitter total. All transmitting equipment at the site must operate from a power source completely independent of the commercial power mains to qualify. (Example: a club operating 3 transmitters plus a GOTA station and using 100% emergency power receives 300 bonus points.) Available to Classes A, B, C, E, and F.',
            ],
            'media_publicity' => [
                'section' => '7.3.2',
                'text' => 'Media Publicity: 100 bonus points may be earned for obtaining publicity from the local media. A copy of the actual media publicity received (newspaper article, etc.) must be submitted to claim the points. Any combination of bona fide media hits would qualify for the bonus points. For example, details of your upcoming or ongoing Field Day activity, or your Field Day results, as posted on a news media site (which could include the media site\'s Facebook, Twitter, or Instagram) would meet the bonus criteria. Available to all Classes.',
            ],
            'public_location' => [
                'section' => '7.3.3',
                'text' => 'Public Location: 100 bonus points for physically locating the Field Day operation in a public place (i.e. shopping center, park, school campus, etc) and actively welcoming the public. Person(s) shall be available to greet the public and be identified by some sort of name badge. The intent is for amateur radio to not only be on display to the public, but to also engage any visitors showing up at your location. Available to Classes A, B and F.',
            ],
            'public_info_booth' => [
                'section' => '7.3.4',
                'text' => 'Public Information Table: 100 bonus points for a Public Information Table at the Field Day site. The purpose is to make appropriate handouts and information available to the visiting public at the site. Available to Classes A, B and F.',
            ],
            'sm_sec_message' => [
                'section' => '7.3.5',
                'text' => 'Message Origination to Section Manager: 100 bonus points for origination of a formal message to the ARRL Section Manager or Section Emergency Coordinator by your group from its site. You should include the club name, number of participants, Field Day location, and number of ARES operators involved with your station. The message must be transmitted during the Field Day period and a copy of it must be included in your submission in either standard NTS or ICS-213 format (or have the equivalent content) or no credit will be given. The message must leave or enter the Field Day operation via amateur radio RF. The Section Manager message is separate from the messages handled in Rule 7.3.6. and may not be claimed for bonus points under that rule. Available to all Classes.',
            ],
            'nts_message' => [
                'section' => '7.3.6',
                'text' => 'Message Handling: 10 points for each formal message originated, relayed, or received and delivered during the Field Day period, up to a maximum of 100 points (ten messages). Copies of each message must be included with the Field Day report. The message to the ARRL SM or SEC under Rule 7.3.5. does not count towards the total of 10 for this bonus. Messages claimed under this bonus must be in either standard NTS or ICS-213 format (or have the equivalent content). All messages claimed for bonus points must leave or enter the Field Day operation via amateur radio RF. Available to all Classes.',
            ],
            'satellite_qso' => [
                'section' => '7.3.7',
                'text' => 'Satellite QSO: 100 bonus points for successfully completing at least one QSO via an amateur radio satellite during the Field Day period. Groups are allowed one dedicated satellite transmitter station without increasing their entry category. Satellite QSOs also count for regular QSO credit. Show them listed separately on the summary sheet as a separate "band." You do not receive an additional bonus for contacting different satellites, though the additional QSOs may be counted for QSO credit. The QSO must be between two Earth stations through a satellite. Stations are limited to one (1) completed QSO on any single channel FM satellite. Available to Classes A, B, and F.',
            ],
            'natural_power' => [
                'section' => '7.3.8',
                'text' => 'Alternate Power: 100 bonus points for Field Day groups making a minimum of five QSOs without using power from commercial mains or petroleum driven generator. This means an "alternate" energy source of power, such as solar, wind, methane or water. This includes batteries charged by natural means (not dry cells). The natural power transmitter counts as an additional transmitter. If you do not wish to increase your operating category, you should take one of your other transmitters off the air while the natural power transmitter is in operation. A separate list of natural power QSOs should be submitted with your entry. Available to Classes A, B, E, and F.',
            ],
            'w1aw_bulletin' => [
                'section' => '7.3.9',
                'text' => 'W1AW Bulletin: 100 bonus points for copying the special Field Day bulletin transmitted by W1AW (or K6KPH) during its operating schedule during the Field Day weekend (listed in this rules announcement). An accurate copy of the message is required to be included in your Field Day submission. (Note: The Field Day bulletin must be copied via amateur radio. It will not be included in Internet bulletins sent out from Headquarters and will not be posted to Internet BBS sites.) Available to all Classes.',
            ],
            'educational_activity' => [
                'section' => '7.3.10',
                'text' => 'Educational activity bonus: One (1) 100-point bonus may be claimed if your Field Day operation includes a specific educational-related activity. The activity can be diverse and must be related to amateur radio. It must be some type of formal activity. It can be repeated during the Field Day period but only one bonus is earned. For more information consult the FAQ in the complete Field Day packet. Available to Classes A & F entries and available clubs or groups operating from a club station in class D and E with 3 or more participants.',
            ],
            'elected_official_visit' => [
                'section' => '7.3.11',
                'text' => 'Site Visitation by an elected governmental official: One (1) 100-point bonus may be claimed if your Field Day site is visited by an elected government official as the result of an invitation issued by your group. Available to all Classes.',
            ],
            'agency_visit' => [
                'section' => '7.3.12',
                'text' => 'Site Visitation by a representative of an agency: One (1) 100-point bonus may be claimed if your Field Day site is visited by a representative of an agency served by ARES in your local community (American Red Cross, Salvation Army, local Emergency Management, law enforcement, etc.) as the result of an invitation issued by your group. ARRL officials (SM, SEC, DEC, EC, etc) do not qualify for this bonus. Available to all Classes.',
            ],
            'gota_qso' => [
                'section' => '7.3.13.1',
                'text' => 'GOTA QSO Bonus: Any successfully completed contacts made by an operator at the GOTA station are worth five (5) bonus points, regardless of mode used. There is no limit to the number of contacts a single GOTA operator can make. The GOTA station bonus points are not multiplied by the power multiplier. Available to Class A and F stations operating a GOTA station.',
            ],
            'gota_coach' => [
                'section' => '7.3.13.2',
                'text' => 'GOTA Coach Bonus: If a GOTA station is supervised by a GOTA Coach, a single 100-point bonus will be earned. The GOTA Coach supervises the operator of the station, doing such things as answering questions and talking them through contacts, but may not make contacts or perform logging functions. To qualify for this bonus, there must be a designated GOTA Coach present and supervising for at least 10 contacts.',
            ],
            'web_submission' => [
                'section' => '7.3.14',
                'text' => 'Web submission: A 50-point bonus may be claimed by a group submitting their Field Day entry via the https://field-day.arrl.org/fdentry.php web app. Available to all Classes.',
            ],
            'youth_participation' => [
                'section' => '7.3.15',
                'text' => 'Field Day Youth Participation: A 20-point bonus (up to a maximum of 100 points) may be earned by any Class A, C, D, E, or F group for each participant age 18 or younger at your Field Day operation that completes at least one QSO. For a 1-person Class B station, a 20-point bonus is earned if the operator is age 18 or younger. For a 2-person Class B station, a 20-point bonus is earned for each operator age 18 or younger (maximum of 40 points). Keep in mind that Class B is only a 1- or 2-person operation. This bonus does not allow the total number of participants in Class B to exceed 1 or 2.',
            ],
            'social_media' => [
                'section' => '7.3.16',
                'text' => 'Social Media: 100 points for promoting your Field Day activation to the general public via an active, recognized and utilized social media platform (Facebook, Twitter, Instagram, etc). This bonus is available to bona fide Amateur Radio clubs and Field Day groups that welcome visitors to their operation. Individual participants do not qualify for this bonus. Club websites do not qualify for this bonus. Available to all classes.',
            ],
            'safety_officer' => [
                'section' => '7.3.17',
                'text' => 'Safety Officer Bonus: A 100-point bonus may be earned by having a person serving as a Safety Officer for those groups setting up Class A stations. This person must verify that all safety concerns on the Safety Officer Check List (found in the ARRL Field Day Packet) have been adequately met. This is an active bonus — simply designating someone as Safety Officer does not automatically earn this bonus. A signed copy of the Safety Officer Check List must be included in the supporting documentation sent to ARRL HQ in order to claim this bonus. Available to Class A entries only.',
            ],
            'site_responsibilities' => [
                'section' => '7.3.18',
                'text' => 'Field Day Site Responsibilities Bonus: A 50-point bonus may be earned by having a person ensure that the Field Day site is free of hazards, and that safety precautions have been taken throughout the entire event, as well as providing a point of contact to the visiting public or served agency officials. A signed copy of the Field Day Responsibilities Check List must be included in the supporting documentation sent to ARRL HQ in order to claim this bonus. Available to Class B, C, D, E, or F entries.',
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
