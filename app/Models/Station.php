<?php

namespace App\Models;

use App\Enums\PowerSource;
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
    /** @use HasFactory<\Database\Factories\StationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'radio_equipment_id',
        'name',
        'power_source_description',
        'power_source',
        'is_gota',
        'is_vhf_only',
        'is_satellite',
        'max_power_watts',
    ];

    protected function casts(): array
    {
        return [
            'power_source' => PowerSource::class,
            'is_gota' => 'boolean',
            'is_vhf_only' => 'boolean',
            'is_satellite' => 'boolean',
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
