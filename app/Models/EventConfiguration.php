<?php

namespace App\Models;

use App\Enums\PowerSource;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Dto\PowerContext;
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
     * Drop the memoized RuleSet so the next resolution re-reads the event's
     * current rules_version. Call after persisting a rules_version change.
     */
    public function forgetResolvedRuleSet(): void
    {
        $this->resolvedRuleSet = null;
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
        return $this->ruleSet()->powerMultiplier($this->powerContext());
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

        $basePoints = $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', false)
            ->sum('points');

        return $basePoints * $this->calculatePowerMultiplier();
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

        $gotaContactCount = $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
            ->count();

        return $gotaContactCount * $this->ruleSet()->gotaPointsPerContact();
    }

    /**
     * Calculate GOTA coach bonus via the event's RuleSet threshold and bonus amount.
     */
    public function calculateGotaCoachBonus(): int
    {
        if (! $this->has_gota_station) {
            return 0;
        }

        $rules = $this->ruleSet();

        $supervisedGotaCount = $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
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
     * Combines auto-counted registered youth with QSOs and any manual
     * additional youth stored in the EventBonus notes field.
     */
    public function calculateYouthBonus(): int
    {
        $rules = $this->ruleSet();
        $autoCount = $this->countYouthWithQsos();

        $bonusType = $rules->bonus('youth_participation');
        $maxOccurrences = $bonusType->max_occurrences ?? $rules->youthMaxCount();
        $basePoints = $bonusType->base_points ?? $rules->youthPointsPerYouth();

        $additional = 0;
        if ($bonusType) {
            $bonus = $this->bonuses()
                ->where('bonus_type_id', $bonusType->id)
                ->first();
            $additional = $bonus ? (int) ($bonus->notes ?? 0) : 0;
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
        $rules = $this->ruleSet();
        $bonusType = $rules->bonus('emergency_power');

        $hasCommercialStation = ! $this->uses_commercial_power
            && $this->stations()
                ->where('power_source', PowerSource::CommercialMains->value)
                ->exists();

        if ($this->uses_commercial_power || $hasCommercialStation || ! $bonusType || ! $bonusType->is_active) {
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

        return min($this->transmitter_count, $rules->emergencyPowerMaxTransmitters()) * $bonusType->base_points;
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
            ->sum('calculated_points');

        return $storedBonuses + $this->calculateYouthBonus() + $this->calculateEmergencyPowerBonus() + $this->calculateSatelliteBonus();
    }

    /**
     * Calculate final score (QSO + bonus).
     */
    public function calculateFinalScore(): int
    {
        return $this->calculateQsoScore()
            + $this->calculateBonusScore()
            + $this->calculateGotaBonus()
            + $this->calculateGotaCoachBonus();
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
