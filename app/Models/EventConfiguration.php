<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventConfiguration extends Model
{
    use HasFactory, SoftDeletes;

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
        'guestbook_latitude',
        'guestbook_longitude',
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
            'guestbook_latitude' => 'decimal:7',
            'guestbook_longitude' => 'decimal:7',
            'guestbook_detection_radius' => 'integer',
            'guestbook_local_subnets' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
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

    public function getHasGuestbookLocationAttribute(): bool
    {
        return $this->guestbook_latitude !== null && $this->guestbook_longitude !== null;
    }

    /**
     * Calculate power multiplier based on 2025 Field Day rules.
     *
     * Uses the higher of the event config power and the highest station power,
     * since any station exceeding the event power level affects the entire entry.
     *
     * 5× = ≤5W + (battery OR solar OR wind OR water) + NOT (commercial OR generator)
     * 2× = (≤5W + commercial/generator) OR (6-100W)
     * 1× = >100W
     */
    public function calculatePowerMultiplier(): string
    {
        $effectivePower = $this->effectiveMaxPowerWatts();

        if ($effectivePower > 100) {
            return '1';
        }

        if ($effectivePower <= 5 && $this->hasQrpNaturalPowerBonus()) {
            return '5';
        }

        // 6-100W or QRP without natural power bonus gets 2x
        return '2';
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
     * Requires natural power sources and no commercial/generator power.
     */
    protected function hasQrpNaturalPowerBonus(): bool
    {
        $hasNaturalPower = $this->uses_battery
            || $this->uses_solar
            || $this->uses_wind
            || $this->uses_water;

        $hasDisqualifyingPower = $this->uses_commercial_power || $this->uses_generator;

        return $hasNaturalPower && ! $hasDisqualifyingPower;
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
     * Calculate GOTA bonus: 5 points per non-duplicate GOTA contact.
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

        return $gotaContactCount * 5;
    }

    /**
     * Calculate GOTA coach bonus: 100 points if 10+ supervised contacts.
     */
    public function calculateGotaCoachBonus(): int
    {
        if (! $this->has_gota_station) {
            return 0;
        }

        $supervisedGotaCount = $this->contacts()
            ->where('is_duplicate', false)
            ->where('is_gota_contact', true)
            ->whereHas('operatingSession', fn ($q) => $q->where('is_supervised', true))
            ->count();

        return $supervisedGotaCount >= 10 ? 100 : 0;
    }

    /**
     * Calculate total bonus points (verified only).
     */
    public function calculateBonusScore(): int
    {
        if (! class_exists(EventBonus::class)) {
            return 0;
        }

        return $this->bonuses()
            ->where('is_verified', true)
            ->sum('calculated_points');
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
