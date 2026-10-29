<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ShiftAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftAssignment extends Model
{
    /** @use HasFactory<ShiftAssignmentFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_NO_SHOW = 'no_show';

    public const SIGNUP_TYPE_ASSIGNED = 'assigned';

    public const SIGNUP_TYPE_SELF_SIGNUP = 'self_signup';

    protected $fillable = [
        'shift_id',
        'user_id',
        'status',
        'checked_in_at',
        'checked_out_at',
        'confirmed_by_user_id',
        'confirmed_at',
        'signup_type',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    // Relationships

    /**
     * Get the shift this assignment belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the user assigned to this shift.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who confirmed this assignment.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    // Scopes

    /**
     * Scope a query to only include assignments for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include checked-in assignments.
     */
    /**
     * Scope to assignments that take up a slot on the shift (everything except no-shows).
     */
    public function scopeCountingTowardCapacity(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_NO_SHOW);
    }

    public function scopeCheckedIn(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CHECKED_IN);
    }

    /**
     * Scope a query to only include assignments pending confirmation.
     */
    public function scopePendingConfirmation(Builder $query): Builder
    {
        return $query->whereNull('confirmed_by_user_id')
            ->where('status', '!=', self::STATUS_NO_SHOW);
    }

    /**
     * Scope a query to assignments that still need a manager to mark them
     * worked or no-show (never checked out and not marked as a no-show).
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_CHECKED_IN]);
    }

    /**
     * Scope a query to only include confirmed assignments.
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_by_user_id');
    }

    // Methods

    /**
     * Create a fresh scheduled assignment, reviving a removed one if it exists.
     *
     * Removing an assignment soft-deletes it, but the row still holds the
     * shift/user unique key, so a plain insert would fail.
     */
    public static function createOrRestore(int $shiftId, int $userId, string $signupType): self
    {
        $attributes = [
            'status' => self::STATUS_SCHEDULED,
            'signup_type' => $signupType,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'confirmed_by_user_id' => null,
            'confirmed_at' => null,
        ];

        $removed = self::onlyTrashed()
            ->where('shift_id', $shiftId)
            ->where('user_id', $userId)
            ->first();

        if ($removed) {
            $removed->restore();
            $removed->update($attributes);

            return $removed;
        }

        return self::create(['shift_id' => $shiftId, 'user_id' => $userId, ...$attributes]);
    }

    /**
     * Check in the user for this shift.
     */
    public function checkIn(): void
    {
        $this->update([
            'status' => self::STATUS_CHECKED_IN,
            'checked_in_at' => appNow(),
        ]);
    }

    /**
     * Check out the user from this shift.
     */
    public function checkOut(): void
    {
        $this->update([
            'status' => self::STATUS_CHECKED_OUT,
            'checked_out_at' => appNow(),
        ]);
    }

    /**
     * Revert a checked-out assignment back to checked-in, preserving checked_in_at.
     */
    public function checkBackIn(): void
    {
        $this->update([
            'status' => self::STATUS_CHECKED_IN,
            'checked_out_at' => null,
        ]);
    }

    /**
     * Confirm this shift assignment and sync any associated event bonus.
     */
    public function confirm(User $manager): void
    {
        $this->update([
            'confirmed_by_user_id' => $manager->id,
            'confirmed_at' => now(),
        ]);

        $this->syncEventBonus();
    }

    /**
     * Revoke confirmation and remove any associated event bonus.
     */
    public function revokeConfirmation(): void
    {
        $this->update([
            'confirmed_by_user_id' => null,
            'confirmed_at' => null,
        ]);

        $this->removeEventBonus();
    }

    /**
     * Mark this assignment as a no-show.
     */
    public function markNoShow(): void
    {
        $this->update([
            'status' => self::STATUS_NO_SHOW,
        ]);
    }

    /**
     * Calculate the hours actually worked on this assignment, rounded to 0.1 hour.
     *
     * Someone still checked in is counted up to now. Returns 0.0 without a
     * check-in, or if the end precedes check-in. Caps the duration at the
     * shift's scheduled length so a late checkout doesn't exceed the planned hours.
     *
     * @note Callers summing across a collection should eager-load the `shift`
     * relation (e.g. `->with('shift')`) to avoid N+1 queries.
     */
    public function hoursWorked(): float
    {
        $workedUntil = $this->workedUntil();

        if ($this->checked_in_at === null || $workedUntil === null) {
            return 0.0;
        }

        $workedMinutes = $this->checked_in_at->diffInMinutes($workedUntil);
        $scheduledMinutes = $this->shift->start_time->diffInMinutes($this->shift->end_time);
        $minutes = max(0, min($workedMinutes, $scheduledMinutes));

        return round($minutes / 60, 1);
    }

    /**
     * When the worked time ends: the checkout, or now for someone still
     * checked in. Null when there's nothing to count.
     */
    public function workedUntil(): ?CarbonInterface
    {
        if ($this->checked_in_at === null) {
            return null;
        }

        if ($this->checked_out_at !== null) {
            return $this->checked_out_at;
        }

        return $this->status === self::STATUS_CHECKED_IN ? appNow() : null;
    }

    /**
     * Scheduled shift duration in hours, rounded to 0.1.
     */
    public function scheduledHours(): float
    {
        return round($this->shift->start_time->diffInMinutes($this->shift->end_time) / 60, 1);
    }

    /**
     * Sync the event bonus for this shift assignment's role, if applicable.
     *
     * Creates or updates an EventBonus record when the shift role maps to a bonus type.
     */
    protected function syncEventBonus(): void
    {
        $context = $this->resolveShiftBonusContext();
        if (! $context) {
            return;
        }

        [$shift, $bonusType] = $context;

        EventBonus::updateOrCreate(
            [
                'event_configuration_id' => $shift->event_configuration_id,
                'bonus_type_id' => $bonusType->id,
            ],
            [
                'claimed_by_user_id' => $this->user_id,
                'quantity' => 1,
                'calculated_points' => $bonusType->base_points,
                'is_verified' => true,
                'verified_by_user_id' => $this->confirmed_by_user_id,
                'verified_at' => now(),
            ]
        );
    }

    /**
     * Remove the event bonus associated with this shift assignment's role.
     */
    protected function removeEventBonus(): void
    {
        $context = $this->resolveShiftBonusContext();
        if (! $context) {
            return;
        }

        [$shift, $bonusType] = $context;

        EventBonus::where('event_configuration_id', $shift->event_configuration_id)
            ->where('bonus_type_id', $bonusType->id)
            ->delete();
    }

    /**
     * Resolve the shift and matching bonus type for this assignment, or null if
     * the role does not map to a bonus or required context is missing.
     *
     * @return array{0: Shift, 1: BonusType}|null
     */
    private function resolveShiftBonusContext(): ?array
    {
        $shift = $this->shift()->with('shiftRole', 'eventConfiguration.event')->first();
        $bonusTypeCode = $shift?->shiftRole?->getBonusTypeCode();
        $event = $shift?->eventConfiguration?->event;

        if (! $shift || ! $bonusTypeCode || ! $event) {
            return null;
        }

        $bonusType = BonusType::resolveFor($event, $bonusTypeCode);

        return $bonusType ? [$shift, $bonusType] : null;
    }
}
