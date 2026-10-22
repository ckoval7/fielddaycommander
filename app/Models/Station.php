<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\PowerSource;
use App\Enums\StationUnavailableReason;
use Carbon\Carbon;
use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'radio_equipment_id',
        'name',
        'tactical_callsign',
        'location',
        'rx_frequency',
        'tx_frequency',
        'tone',
        'comms_mode',
        'hostname',
        'power_source_description',
        'power_source',
        'is_gota',
        'is_vhf_only',
        'is_satellite',
        'max_power_watts',
        'unavailable_reason',
        'unavailable_note',
        'unavailable_since',
        'unavailable_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'power_source' => PowerSource::class,
            'is_gota' => 'boolean',
            'is_vhf_only' => 'boolean',
            'is_satellite' => 'boolean',
            'unavailable_reason' => StationUnavailableReason::class,
            'unavailable_since' => 'datetime',
        ];
    }

    // Relationships

    /**
     * Get the event configuration this station belongs to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the primary radio equipment for this station.
     */
    public function primaryRadio(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'radio_equipment_id');
    }

    /**
     * Get all additional equipment assigned to this station via the equipment_event pivot table.
     *
     * This relationship returns equipment that is assigned to this station through the
     * equipment_event pivot, excluding the primary radio which is tracked separately.
     */
    public function additionalEquipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'equipment_event', 'station_id', 'equipment_id')
            ->withPivot([
                'event_id',
                'assigned_by_user_id',
                'status',
                'committed_at',
                'expected_delivery_at',
                'delivery_notes',
                'manager_notes',
                'status_changed_at',
                'status_changed_by_user_id',
            ])
            ->withTimestamps();
    }

    /**
     * Get the user who marked this station unavailable.
     */
    public function unavailableBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unavailable_by_user_id');
    }

    /**
     * Get the browsers enrolled to run station mode for this station.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(StationDevice::class);
    }

    /**
     * Get all operating sessions for this station.
     */
    public function operatingSessions(): HasMany
    {
        return $this->hasMany(OperatingSession::class);
    }

    /**
     * Get all contacts logged from this station.
     *
     * Contacts are linked to stations through operating sessions.
     */
    public function contacts(): HasManyThrough
    {
        return $this->hasManyThrough(Contact::class, OperatingSession::class);
    }

    // Accessors

    /**
     * Get the count of additional equipment assigned to this station.
     *
     * This count excludes the primary radio, which is tracked separately.
     */
    protected function equipmentCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->additionalEquipment()->count()
        );
    }

    /**
     * Check if this station has any active operating sessions.
     *
     * A station is considered active if it has any operating sessions
     * where the end_time is NULL (session is still ongoing).
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->operatingSessions()
                ->whereNull('end_time')
                ->exists()
        );
    }

    /**
     * Get the total number of QSOs logged from this station.
     */
    protected function contactCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->contacts()->count()
        );
    }

    // Readiness

    /**
     * Determine whether a station captain has marked this station unavailable.
     */
    public function isUnavailable(): bool
    {
        return $this->unavailable_reason !== null;
    }

    /**
     * Mark this station unavailable and end any active operating sessions.
     *
     * @return int The number of operating sessions that were ended
     */
    public function markUnavailable(StationUnavailableReason $reason, ?string $note, User $markedBy): int
    {
        $this->update([
            'unavailable_reason' => $reason,
            'unavailable_note' => filled($note) ? $note : null,
            'unavailable_since' => appNow(),
            'unavailable_by_user_id' => $markedBy->id,
        ]);

        return $this->operatingSessions()
            ->whereNull('end_time')
            ->update(['end_time' => appNow()]);
    }

    /**
     * Return this station to service.
     */
    public function markAvailable(): void
    {
        $this->update([
            'unavailable_reason' => null,
            'unavailable_note' => null,
            'unavailable_since' => null,
            'unavailable_by_user_id' => null,
        ]);
    }

    /**
     * Compute the operating status of this station.
     *
     * Returns 'unavailable' (marked out of service by a station captain),
     * 'available' (no active session), 'idle' (active session but no QSO in
     * the last 30 minutes), or 'occupied' (active and working).
     *
     * Expects active operatingSessions to be eager-loaded for efficiency.
     */
    public function operatingStatus(): string
    {
        if ($this->isUnavailable()) {
            return 'unavailable';
        }

        $activeSession = $this->operatingSessions
            ->whereNull('end_time')
            ->first();

        if (! $activeSession) {
            return 'available';
        }

        $lastActivity = $activeSession->contacts()
            ->latest('qso_time')
            ->value('qso_time');

        $idleThreshold = appNow()->subMinutes(30);
        $referenceTime = $lastActivity ? Carbon::parse($lastActivity) : $activeSession->start_time;

        return $referenceTime->lt($idleThreshold) ? 'idle' : 'occupied';
    }

    // Scopes

    /**
     * Scope a query to only include stations for a specific event.
     *
     * @param  int  $eventId  The event_configuration_id to filter by
     */
    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->where('event_configuration_id', $eventId);
    }

    /**
     * Scope a query to only include stations ready for operation.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->whereNull('unavailable_reason');
    }

    /**
     * Scope a query to only include stations marked unavailable.
     */
    public function scopeUnavailable(Builder $query): Builder
    {
        return $query->whereNotNull('unavailable_reason');
    }

    /**
     * Scope a query to only include GOTA (Get On The Air) stations.
     */
    public function scopeGota(Builder $query): Builder
    {
        return $query->where('is_gota', true);
    }

    /**
     * Scope a query to only include non-GOTA stations.
     */
    public function scopeNonGota(Builder $query): Builder
    {
        return $query->where('is_gota', false);
    }
}
