<?php

namespace App\Models;

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
     * Scope a query to only include confirmed assignments.
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_by_user_id');
    }

    // Methods

    /**
     * Check in the user for this shift.
     */
    public function checkIn(): void
    {
        $this->update([
            'status' => self::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
        ]);
    }

    /**
     * Check out the user from this shift.
     */
    public function checkOut(): void
    {
        $this->update([
            'status' => self::STATUS_CHECKED_OUT,
            'checked_out_at' => now(),
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
     * Returns 0.0 if either check-in or check-out is missing. Caps the duration at
     * the shift's scheduled length so a late checkout doesn't exceed the planned hours.
     */
    public function hoursWorked(): float
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return 0.0;
        }

        $workedMinutes = $this->checked_in_at->diffInMinutes($this->checked_out_at);
        $scheduledMinutes = $this->shift->start_time->diffInMinutes($this->shift->end_time);
        $minutes = min($workedMinutes, $scheduledMinutes);

        return round($minutes / 60, 1);
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
        $shift = $this->shift()->with('shiftRole', 'eventConfiguration')->first();
        if (! $shift) {
            return;
        }

        $bonusTypeCode = $shift->shiftRole->getBonusTypeCode();
        if (! $bonusTypeCode) {
            return;
        }

        $bonusType = BonusType::where('code', $bonusTypeCode)->first();
        if (! $bonusType) {
            return;
        }

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
        $shift = $this->shift()->with('shiftRole', 'eventConfiguration')->first();
        if (! $shift) {
            return;
        }

        $bonusTypeCode = $shift->shiftRole->getBonusTypeCode();
        if (! $bonusTypeCode) {
            return;
        }

        $bonusType = BonusType::where('code', $bonusTypeCode)->first();
        if (! $bonusType) {
            return;
        }

        EventBonus::where('event_configuration_id', $shift->event_configuration_id)
            ->where('bonus_type_id', $bonusType->id)
            ->delete();
    }
}
