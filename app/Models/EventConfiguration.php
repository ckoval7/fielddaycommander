<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\PowerSource;
use App\Scoring\Contracts\DeterminesContactCredit;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\PowerContext;
use App\Scoring\Dto\ScoreBreakdown;
use App\Scoring\RuleSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    /** Memoized RuleSet — resolved once per instance. */
    protected ?RuleSet $resolvedRuleSet = null;

    protected $fillable = [
        'event_id',
        'created_by_user_id',
        'callsign',
        'club_name',
        'logo_path',
        'tagline',
        'is_active',
        'section_id',
        'operating_class_id',
        'transmitter_count',
        'has_gota_station',
        'gota_callsign',
        'max_power_watts',
        'power_multiplier',
        'uses_commercial_power',
        'uses_generator',
        'uses_battery',
        'uses_solar',
        'uses_wind',
        'uses_water',
        'uses_methane',
        'uses_other_power',
        'guestbook_enabled',
        'grid_square',
        'latitude',
        'longitude',
        'city',
        'state',
        'talk_in_frequency',
        'guestbook_detection_radius',
        'guestbook_local_subnets',
        'is_open_to_public',
        'public_directions',
        'public_visiting_hours',
        'public_contact_email',
        'public_contact_phone',
        'public_website_url',
        'public_description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'has_gota_station' => 'boolean',
            'uses_commercial_power' => 'boolean',
            'uses_generator' => 'boolean',
            'uses_battery' => 'boolean',
            'uses_solar' => 'boolean',
            'uses_wind' => 'boolean',
            'uses_water' => 'boolean',
            'uses_methane' => 'boolean',
            'guestbook_enabled' => 'boolean',
            'is_open_to_public' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'guestbook_detection_radius' => 'integer',
            'guestbook_local_subnets' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Resolve the RuleSet for this event configuration, memoized per instance.
     */
    protected function ruleSet(): RuleSet
    {
        if ($this->resolvedRuleSet !== null) {
            return $this->resolvedRuleSet;
        }

        $event = $this->event ?? $this->event()->first();

        return $this->resolvedRuleSet = app(RuleSetFactory::class)->forEvent($event);
    }

    /**
     * Whether this configuration's event is scored as a contest.
     */
    public function isScored(): bool
    {
        return ($this->event ?? $this->event()->first())?->isScored() ?? true;
    }

    /**
     * Drop the memoized RuleSet so the next resolution re-reads the event's
     * current rules_version. Call after persisting a rules_version change.
     */
    public function forgetResolvedRuleSet(): void
    {
        $this->resolvedRuleSet = null;
    }

    /**
     * The pinned ruleset, but only when it is an ARRL Field Day rulebook.
     *
     * Returns null for rulebooks built on a different model (e.g. Winter Field
     * Day), so the Field-Day-only calculators below degrade to a neutral value
     * instead of calling methods that rulebook does not implement.
     */
    protected function fieldDayRules(): ?FieldDayRuleSet
    {
        $rules = $this->ruleSet();

        return $rules instanceof FieldDayRuleSet ? $rules : null;
    }

    /**
     * Compose this configuration's score under its pinned ruleset.
     */
    public function scoreBreakdown(): ScoreBreakdown
    {
        return $this->ruleSet()->score($this);
    }

    /**
     * The award terminology used by this configuration's rulebook.
     */
    public function nomenclature(): Nomenclature
    {
        return $this->ruleSet()->nomenclature();
    }

    /**
     * Cabrillo CONTEST: identifier for this configuration's rulebook.
     */
    public function cabrilloContestName(): string
    {
        return $this->ruleSet()->cabrilloContestName();
    }

    /**
     * Slug this configuration's rulebook uses in exported log filenames.
     */
    public function logFilenameSlug(): string
    {
        return $this->ruleSet()->logFilenameSlug();
    }

    /**
     * Whether this event's rulebook submits on ARRL's Field Day entry form.
     */
    public function usesArrlSubmissionSheet(): bool
    {
        return $this->fieldDayRules() !== null;
    }

    /**
     * Whether this configuration's rulebook scores with a power multiplier.
     */
    public function usesPowerMultiplier(): bool
    {
        return $this->fieldDayRules() !== null;
    }

    /**
     * Sum of points on non-duplicate, non-GOTA contacts, before any multiplier.
     */
    public function qsoBasePoints(): int
    {
        if (! class_exists(Contact::class)) {
            return 0;
        }

        return (int) $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', false)
            ->sum('points');
    }

    /**
     * Resolve points for a single contact via the event's pinned RuleSet.
     *
     * Handles GOTA flat-rate and mode_rule_points overrides internally.
     */
    public function pointsForContact(Mode $mode, Station $station): int
    {
        return $this->ruleSet()->pointsForContact($mode, $station);
    }

    /**
     * Non-duplicate contacts that earn credit under this configuration's rulebook.
     *
     * The single entry point for "which contacts count": QSO points, objectives,
     * and submitted logs (Cabrillo, ADIF, summary sheets) should all start here.
     * Contacts a rulebook gives no credit (e.g. WFD 2027 WSJT-mode or satellite
     * contacts) are left out; rulebooks without such rules return every
     * non-duplicate contact. GOTA contacts are included — filter them yourself
     * where the rulebook scores them separately.
     *
     * @return HasMany<Contact, EventConfiguration>
     */
    public function creditableContacts(): HasMany
    {
        $query = $this->contacts()->where('is_duplicate', false);

        $this->contactCreditRules()?->constrainToCreditable($query);

        return $query;
    }

    /**
     * Why a contact earns no credit under this rulebook, or null when it does.
     *
     * Duplicates are a separate concern and are not reported here. Reads the
     * contact's `band` relation; eager-load it when checking many contacts.
     */
    public function contactNoCreditReason(Contact $contact): ?string
    {
        return $this->contactCreditRules()?->noCreditReason($contact);
    }

    /**
     * The rulebook's contact-credit rules, or null when every contact earns credit.
     *
     * Exposed so callers checking a page of contacts can resolve the rules once.
     */
    public function contactCreditRules(): ?DeterminesContactCredit
    {
        $rules = $this->ruleSet();

        return $rules instanceof DeterminesContactCredit ? $rules : null;
    }

    /**
     * Build a PowerContext from this configuration's effective power state.
     */
    protected function powerContext(): PowerContext
    {
        return new PowerContext(
            effectivePowerWatts: $this->effectiveMaxPowerWatts(),
            qualifiesForQrpNaturalBonus: $this->hasQrpNaturalPowerBonus(),
        );
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function operatingClass(): BelongsTo
    {
        return $this->belongsTo(OperatingClass::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(EventBonus::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function guestbookEntries(): HasMany
    {
        return $this->hasMany(GuestbookEntry::class);
    }

    public function shiftRoles(): HasMany
    {
        return $this->hasMany(ShiftRole::class);
    }

    public function safetyChecklistItems(): HasMany
    {
        return $this->hasMany(SafetyChecklistItem::class);
    }

    public function netLogEntries(): HasMany
    {
        return $this->hasMany(NetLogEntry::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function w1awBulletin(): HasOne
    {
        return $this->hasOne(W1awBulletin::class);
    }

    public function getHasLocationAttribute(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Calculate power multiplier via the event's pinned RuleSet.
     */
    public function calculatePowerMultiplier(): string
    {
        return $this->fieldDayRules()?->powerMultiplier($this->powerContext()) ?? '1';
    }

    /**
     * Get the effective max power considering both event config and station power levels.
     *
     * Per ARRL Field Day rules, the power multiplier applies to the entire entry.
     * If any station uses higher power than the event config, that becomes the
     * effective power for scoring purposes.
     */
    public function effectiveMaxPowerWatts(): int
    {
        $stationMaxPower = $this->stations()->max('max_power_watts') ?? 0;

        return max($this->max_power_watts, $stationMaxPower);
    }

    /**
     * Check if station qualifies for QRP natural power bonus (5x multiplier).
     *
     * Requires natural power sources at event level and no commercial/generator power.
     * Also checks that all stations with a power_source set use natural power.
     */
    protected function hasQrpNaturalPowerBonus(): bool
    {
        $hasNaturalPower = $this->uses_battery
            || $this->uses_solar
            || $this->uses_wind
            || $this->uses_water;

        $hasDisqualifyingPower = $this->uses_commercial_power || $this->uses_generator;

        if (! $hasNaturalPower || $hasDisqualifyingPower) {
            return false;
        }

        // Check station-level power sources: any non-natural station disqualifies
        $naturalValues = collect(PowerSource::cases())
            ->filter(fn (PowerSource $ps) => $ps->isNaturalPower())
            ->map(fn (PowerSource $ps) => $ps->value)
            ->all();

        $hasDisqualifyingStation = $this->stations()
            ->whereNotNull('power_source')
            ->whereNotIn('power_source', $naturalValues)
            ->exists();

        return ! $hasDisqualifyingStation;
    }

    /**
     * Calculate total QSO score (points × power multiplier).
     */
    public function calculateQsoScore(): int
    {
        if (! class_exists(Contact::class)) {
            return 0;
        }

        return $this->qsoBasePoints() * (int) $this->calculatePowerMultiplier();
    }

    /**
     * Calculate GOTA bonus: points per non-duplicate GOTA contact, per the event's RuleSet.
     * Not multiplied by power multiplier.
     */
    public function calculateGotaBonus(): int
    {
        if (! $this->has_gota_station) {
            return 0;
        }

        $rules = $this->fieldDayRules();

        if (! $rules) {
            return 0;
        }

        $gotaContactCount = $this->scoredGotaContacts()->count();

        return $gotaContactCount * $rules->gotaPointsPerContact();
    }

    /**
     * Non-duplicate GOTA contacts logged within the event period: the same
     * contacts DuplicateCheckService prices, since anything outside the
     * window earns 0 points. With no event window set, every contact counts.
     *
     * @return HasMany<Contact, $this>
     */
    protected function scoredGotaContacts(): HasMany
    {
        $event = $this->event;

        return $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
            ->when($event?->start_time !== null && $event?->end_time !== null, fn ($query) => $query
                ->where(fn ($inPeriod) => $inPeriod
                    ->whereBetween('qso_time', [$event->start_time, $event->end_time])
                    ->orWhereNull('qso_time')));
    }

    /**
     * Calculate GOTA coach bonus via the event's RuleSet threshold and bonus amount.
     */
    public function calculateGotaCoachBonus(): int
    {
        if (! $this->has_gota_station) {
            return 0;
        }

        $rules = $this->fieldDayRules();

        if (! $rules) {
            return 0;
        }

        $supervisedGotaCount = $this->scoredGotaContacts()
            ->whereHas('operatingSession', fn ($q) => $q->where('is_supervised', true))
            ->count();

        return $supervisedGotaCount >= $rules->gotaCoachThreshold() ? $rules->gotaCoachBonus() : 0;
    }

    /**
     * Count distinct registered youth users who completed at least one non-duplicate QSO.
     */
    public function countYouthWithQsos(): int
    {
        $youthUserIds = User::where('is_youth', true)->pluck('id');

        if ($youthUserIds->isEmpty()) {
            return 0;
        }

        $loggerIds = $this->contacts()
            ->notDuplicate()
            ->whereIn('logger_user_id', $youthUserIds)
            ->distinct()
            ->pluck('logger_user_id');

        $gotaOperatorIds = $this->contacts()
            ->notDuplicate()
            ->where('is_gota_contact', true)
            ->whereIn('gota_operator_user_id', $youthUserIds)
            ->distinct()
            ->pluck('gota_operator_user_id');

        return $loggerIds->merge($gotaOperatorIds)->unique()->count();
    }

    /**
     * Calculate youth participation bonus via the event's RuleSet.
     *
     * Combines auto-counted registered youth with QSOs and the youth with no
     * account entered on the event's Youth Participation card.
     */
    public function calculateYouthBonus(): int
    {
        $rules = $this->fieldDayRules();

        if (! $rules) {
            return 0;
        }

        $autoCount = $this->countYouthWithQsos();

        $bonusType = $rules->bonus('youth_participation');
        $maxOccurrences = $bonusType->max_occurrences ?? $rules->youthMaxCount();
        $basePoints = $bonusType->base_points ?? $rules->youthPointsPerYouth();

        $additional = 0;
        if ($bonusType) {
            $bonus = $this->bonuses()
                ->where('bonus_type_id', $bonusType->id)
                ->first();
            $additional = (int) ($bonus?->manual_quantity_adjustment ?? 0);
        }

        $total = min($autoCount + $additional, $maxOccurrences);

        return $total * $basePoints;
    }

    /**
     * Calculate emergency power bonus via the event's RuleSet.
     *
     * Awarded when running 100% on emergency power (no commercial power)
     * and the operating class is eligible for the bonus.
     * Disqualified if the event config uses commercial power OR any station
     * has its power_source set to CommercialMains.
     */
    public function calculateEmergencyPowerBonus(): int
    {
        $rules = $this->fieldDayRules();
        $bonusType = $rules?->bonus('emergency_power');

        $hasCommercialStation = ! $this->uses_commercial_power
            && $this->stations()
                ->where('power_source', PowerSource::CommercialMains->value)
                ->exists();

        $disqualified = ! $rules
            || ! $bonusType
            || ! $bonusType->is_active
            || $this->uses_commercial_power
            || $hasCommercialStation
            || ! $this->isClassEligibleForBonus($bonusType);

        if ($disqualified) {
            return 0;
        }

        return min($this->transmitter_count, $rules->emergencyPowerMaxTransmitters()) * $bonusType->base_points;
    }

    /**
     * Determine whether this event's operating class may claim the given bonus.
     *
     * A bonus with no `eligible_classes` restriction is open to every class.
     * The column is decoded defensively because older rows store the list as a
     * JSON string rather than an array.
     */
    public function isClassEligibleForBonus(BonusType $bonusType): bool
    {
        $eligibleClasses = $bonusType->eligible_classes;

        if ($eligibleClasses === null) {
            return true;
        }

        if (is_string($eligibleClasses)) {
            $eligibleClasses = json_decode($eligibleClasses, true) ?? [];
        }

        return in_array($this->operatingClass?->code, $eligibleClasses);
    }

    /**
     * Calculate satellite QSO bonus via the event's RuleSet.
     *
     * Only eligible for classes A, B, F (per bonus type eligible_classes).
     */
    public function calculateSatelliteBonus(): int
    {
        $bonusType = $this->ruleSet()->bonus('satellite_qso');

        if (! $bonusType || ! $bonusType->is_active) {
            return 0;
        }

        $classCode = $this->operatingClass?->code;
        $eligibleClasses = $bonusType->eligible_classes;

        if ($eligibleClasses !== null) {
            if (is_string($eligibleClasses)) {
                $eligibleClasses = json_decode($eligibleClasses, true) ?? [];
            }
            if (! in_array($classCode, $eligibleClasses)) {
                return 0;
            }
        }

        $hasSatelliteContact = $this->contacts()
            ->notDuplicate()
            ->where('is_satellite', true)
            ->exists();

        return $hasSatelliteContact ? (int) $bonusType->base_points : 0;
    }

    /**
     * Calculate total bonus points (verified event_bonuses + computed bonuses).
     */
    public function calculateBonusScore(): int
    {
        if (! class_exists(EventBonus::class)) {
            return 0;
        }

        $storedBonuses = (int) $this->bonuses()
            ->where('is_verified', true)
            ->whereHas('bonusType', fn ($q) => $q->whereNotIn('code', ['youth_participation', 'emergency_power', 'satellite_qso']))
            ->with('bonusType')
            ->get()
            ->filter(fn (EventBonus $bonus): bool => $this->isClassEligibleForBonus($bonus->bonusType))
            ->sum('calculated_points');

        return $storedBonuses + $this->calculateYouthBonus() + $this->calculateEmergencyPowerBonus() + $this->calculateSatelliteBonus();
    }

    /**
     * Calculate final score, as composed by the event's pinned RuleSet.
     */
    public function calculateFinalScore(): int
    {
        return $this->scoreBreakdown()->total;
    }

    /**
     * Check if configuration has any contacts.
     */
    public function hasContacts(): bool
    {
        if (! class_exists(Contact::class)) {
            return false;
        }

        return $this->contacts()->exists();
    }

    /**
     * Check if configuration has any GOTA contacts.
     */
    public function hasGotaContacts(): bool
    {
        if (! class_exists(Contact::class)) {
            return false;
        }

        return $this->contacts()
            ->where('is_gota_contact', true)
            ->exists();
    }

    /**
     * Check if configuration is locked (has contacts OR event has started).
     */
    public function isLocked(): bool
    {
        // If has contacts, it's locked
        if ($this->hasContacts()) {
            return true;
        }

        // If event has started, it's locked
        if ($this->event && $this->event->start_time <= appNow()) {
            return true;
        }

        return false;
    }
}
