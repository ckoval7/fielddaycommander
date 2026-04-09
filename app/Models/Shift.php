<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'shift_role_id',
        'start_time',
        'end_time',
        'capacity',
        'is_open',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'is_open' => 'boolean',
        ];
    }

    // Relationships

    /**
     * Get the event configuration this shift belongs to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the shift role for this shift.
     */
    public function shiftRole(): BelongsTo
    {
        return $this->belongsTo(ShiftRole::class);
    }

    /**
     * Get the assignments for this shift.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    // Scopes

    /**
     * Scope a query to only include shifts for a specific event configuration.
     */
    public function scopeForEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('shifts.event_configuration_id', $eventConfigurationId);
    }

    /**
     * Scope a query to only include shifts for a specific role.
     */
    public function scopeForRole(Builder $query, int $shiftRoleId): Builder
    {
        return $query->where('shift_role_id', $shiftRoleId);
    }

    /**
     * Scope a query to order shifts chronologically.
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('start_time');
    }

    /**
     * Scope a query to only include open shifts (available for self-signup).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }

    // Accessors

    /**
     * Get the count of filled assignment slots.
     */
    protected function filledCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->assignments()->count()
        );
    }

    /**
     * Check if the shift has remaining capacity.
     */
    protected function hasCapacity(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->assignments()->count() < $this->capacity
        );
    }

    /**
     * Check if this shift is currently happening.
     */
    protected function isCurrent(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => appNow()->between($this->start_time, $this->end_time)
        );
    }

    /**
     * Check if this shift has already ended.
     */
    protected function isPast(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => appNow()->isAfter($this->end_time)
        );
    }

    /**
     * Check if check-in is available (within 15 minutes before start through end).
     */
    protected function canCheckIn(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => appNow()->isAfter($this->start_time->copy()->subMinutes(15))
                && appNow()->isBefore($this->end_time)
        );
    }

    /**
     * Check if this shift has not yet started.
     */
    protected function isUpcoming(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => appNow()->isBefore($this->start_time)
        );
    }
}
