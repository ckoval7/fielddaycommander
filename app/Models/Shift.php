<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Support\LocalTimeRange;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
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
            get: fn (): bool => $this->assignments()
                ->countingTowardCapacity()
                ->count() < $this->capacity
        );
    }

    /**
     * The user's other assignments in this event whose shifts overlap this one.
     * No-shows are left out. Shifts that only touch end to start don't overlap.
     *
     * @return Collection<int, ShiftAssignment>
     */
    public function overlappingAssignmentsFor(int $userId): Collection
    {
        return ShiftAssignment::query()
            ->forUser($userId)
            ->countingTowardCapacity()
            ->where('shift_id', '!=', $this->id)
            ->whereHas('shift', fn (Builder $query) => $query
                ->where('event_configuration_id', $this->event_configuration_id)
                ->where('start_time', '<', $this->end_time)
                ->where('end_time', '>', $this->start_time))
            ->with('shift.shiftRole')
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment) => $assignment->shift->start_time)
            ->values();
    }

    /**
     * List the shifts behind a set of assignments for a message,
     * such as "Safety Officer (Oct 28, 2:00 PM to 4:00 PM EDT)".
     *
     * @param  Collection<int, ShiftAssignment>  $assignments  with `shift.shiftRole` loaded
     */
    public static function describeAssignments(Collection $assignments): string
    {
        return $assignments
            ->map(fn (ShiftAssignment $assignment) => ($assignment->shift->shiftRole?->name ?? 'Shift')
                .' ('.LocalTimeRange::format($assignment->shift->start_time, $assignment->shift->end_time).')')
            ->join(', ', ' and ');
    }

    /**
     * Count the assignments that take up a slot (everything except no-shows).
     * Uses the loaded assignments relation when present, so lists don't re-query.
     */
    public function slotsTaken(): int
    {
        if ($this->relationLoaded('assignments')) {
            return $this->assignments
                ->where('status', '!=', ShiftAssignment::STATUS_NO_SHOW)
                ->count();
        }

        return $this->assignments()->countingTowardCapacity()->count();
    }

    /**
     * Raw SQL subquery counting the slots taken on a shift row, for filters and sorting.
     * Mirrors ShiftAssignment::scopeCountingTowardCapacity.
     */
    public static function slotsTakenSql(): string
    {
        $noShow = ShiftAssignment::STATUS_NO_SHOW;

        return "(select count(*) from shift_assignments where shift_assignments.shift_id = shifts.id and shift_assignments.deleted_at is null and shift_assignments.status != '{$noShow}')";
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

    /**
     * Check if this shift urgently needs coverage (starts within 2 hours, no assignments).
     */
    protected function isUrgentlyEmpty(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                if (! $this->is_upcoming || ! $this->is_open) {
                    return false;
                }

                if ($this->start_time->gt(appNow()->addHours(2))) {
                    return false;
                }

                return $this->relationLoaded('assignments')
                    ? $this->assignments->isEmpty()
                    : $this->assignments()->count() === 0;
            }
        );
    }
}
