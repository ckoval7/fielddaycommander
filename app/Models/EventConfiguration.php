<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Calculate power multiplier based on 2025 Field Day rules.
     *
     * 5× = ≤5W + (battery OR solar OR wind OR water) + NOT (commercial OR generator)
     * 2× = (≤5W + commercial/generator) OR (6-100W)
     * 1× = >100W
     */
    public function calculatePowerMultiplier(): int
    {
        // Over 100W always gets 1x
        if ($this->max_power_watts > 100) {
            return 1;
        }

        // 5W or less qualifies for potential 5x
        if ($this->max_power_watts <= 5) {
            // Check for natural power sources (battery, solar, wind, water)
            $hasNaturalPower = $this->uses_battery
                || $this->uses_solar
                || $this->uses_wind
                || $this->uses_water;

            // Check for disqualifying power sources (commercial or generator)
            $hasDisqualifyingPower = $this->uses_commercial_power || $this->uses_generator;

            // 5x if natural power and no commercial/generator
            if ($hasNaturalPower && ! $hasDisqualifyingPower) {
                return 5;
            }

            // Otherwise QRP with commercial/generator gets 2x
            return 2;
        }

        // 6-100W always gets 2x regardless of power source
        return 2;
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
            ->sum('points');

        return $basePoints * $this->calculatePowerMultiplier();
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
        return $this->calculateQsoScore() + $this->calculateBonusScore();
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
        if ($this->event && $this->event->start_time <= now()) {
            return true;
        }

        return false;
    }
}
