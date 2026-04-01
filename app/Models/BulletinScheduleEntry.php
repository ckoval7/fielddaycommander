<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulletinScheduleEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'scheduled_at',
        'mode',
        'frequencies',
        'source',
        'notification_sent',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'notification_sent' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_at', '>', appNow());
    }

    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Entries due for a 15-minute reminder that haven't been sent yet.
     *
     * Looks up to 5 minutes in the past to catch entries if the scheduler
     * was slightly delayed, and up to 15 minutes in the future.
     */
    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->where('notification_sent', false)
            ->whereBetween('scheduled_at', [appNow()->subMinutes(5), appNow()->addMinutes(15)]);
    }

    /**
     * Format the mode for display (uppercase first letter).
     */
    public function getModeLabelAttribute(): string
    {
        return match ($this->mode) {
            'cw' => 'CW',
            'digital' => 'Digital',
            'phone' => 'Phone',
            default => ucfirst($this->mode),
        };
    }
}
