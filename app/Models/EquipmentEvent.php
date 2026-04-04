<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EquipmentEvent model representing equipment commitments for events.
 *
 * @property int $id
 * @property int $equipment_id
 * @property int $event_id
 * @property int|null $station_id
 * @property int|null $assigned_by_user_id
 * @property string $status
 * @property \Illuminate\Support\Carbon $committed_at
 * @property \Illuminate\Support\Carbon|null $expected_delivery_at
 * @property string|null $delivery_notes
 * @property string|null $manager_notes
 * @property \Illuminate\Support\Carbon $status_changed_at
 * @property int|null $status_changed_by_user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EquipmentEvent extends Model
{
    /** @use HasFactory<\Database\Factories\EquipmentEventFactory> */
    use HasFactory;

    protected $table = 'equipment_event';

    /**
     * All valid equipment statuses.
     */
    public const STATUSES = [
        'committed',
        'delivered',
        'returned',
        'cancelled',
        'lost',
        'damaged',
    ];

    protected $fillable = [
        'equipment_id',
        'event_id',
        'station_id',
        'assigned_by_user_id',
        'status',
        'committed_at',
        'expected_delivery_at',
        'delivery_notes',
        'manager_notes',
        'status_changed_at',
        'status_changed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'expected_delivery_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    // Relationships

    /**
     * Get the equipment for this commitment.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * Get the event for this commitment.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the station assigned to this equipment (if any).
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the user who made the assignment.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * Get the user who last changed the status.
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    // Scopes

    /**
     * Scope a query to only include equipment for a specific event.
     */
    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope a query to only include equipment with a specific status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include equipment with issues (cancelled, lost, or damaged).
     */
    public function scopeHasIssues(Builder $query): Builder
    {
        return $query->whereIn('status', ['cancelled', 'lost', 'damaged']);
    }

    /**
     * Scope a query to only include equipment that needs to be returned.
     */
    public function scopeNeedsReturn(Builder $query): Builder
    {
        return $query->whereIn('status', ['delivered']);
    }

    /**
     * Scope a query to only include equipment owned by a specific user.
     */
    public function scopeByOwner(Builder $query, int $userId): Builder
    {
        return $query->whereHas('equipment', function (Builder $q) use ($userId) {
            $q->where('owner_user_id', $userId);
        });
    }

    /**
     * Scope a query to only include equipment assigned to a specific station.
     */
    public function scopeAssignedToStation(Builder $query, int $stationId): Builder
    {
        return $query->where('station_id', $stationId);
    }

    // Methods

    /**
     * Change the status of the equipment commitment.
     *
     * Validates the transition, updates status tracking fields, and appends notes.
     *
     * @param  string  $newStatus  The new status to transition to
     * @param  User  $user  The user performing the status change
     * @param  string|null  $notes  Optional notes to append to manager_notes
     * @return bool True if the status was changed successfully, false if transition is invalid
     */
    public function changeStatus(string $newStatus, User $user, ?string $notes = null): bool
    {
        if (! in_array($newStatus, self::STATUSES, true)) {
            return false;
        }

        $this->status = $newStatus;
        $this->status_changed_at = now();
        $this->status_changed_by_user_id = $user->id;

        if ($notes !== null) {
            $timestamp = now()->format('Y-m-d H:i:s');
            $userName = $user->call_sign ?? trim("{$user->first_name} {$user->last_name}");
            $noteEntry = "[{$timestamp}] {$userName}: {$notes}";

            if ($this->manager_notes) {
                $this->manager_notes .= "\n{$noteEntry}";
            } else {
                $this->manager_notes = $noteEntry;
            }
        }

        return $this->save();
    }

    /**
     * Check if a status value is valid.
     *
     * @param  string  $newStatus  The status to validate
     * @return bool True if the status is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::STATUSES, true);
    }

    /**
     * Check if this equipment commitment overlaps with another event.
     *
     * Queries for any other equipment_event records for the same equipment
     * where the event dates overlap and status is not cancelled or returned.
     *
     * @param  int  $eventId  The ID of the event to check for overlaps
     * @return bool True if there is an overlapping commitment, false otherwise
     */
    public function isOverlapping(int $eventId): bool
    {
        // Get the target event
        $targetEvent = Event::find($eventId);

        if (! $targetEvent) {
            return false;
        }

        // Get this equipment's current event
        $currentEvent = $this->event;

        if (! $currentEvent) {
            return false;
        }

        // Check if the equipment is committed to another event with overlapping dates
        return self::query()
            ->where('equipment_id', $this->equipment_id)
            ->where('event_id', '!=', $this->event_id)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereHas('event', function (Builder $query) use ($targetEvent) {
                $query->where(function (Builder $q) use ($targetEvent) {
                    // Event starts during target event
                    $q->whereBetween('start_time', [$targetEvent->start_time, $targetEvent->end_time])
                        // Event ends during target event
                        ->orWhereBetween('end_time', [$targetEvent->start_time, $targetEvent->end_time])
                        // Event completely encompasses target event
                        ->orWhere(function (Builder $q2) use ($targetEvent) {
                            $q2->where('start_time', '<=', $targetEvent->start_time)
                                ->where('end_time', '>=', $targetEvent->end_time);
                        });
                });
            })
            ->exists();
    }
}
