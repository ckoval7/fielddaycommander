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

    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->where('notification_sent', false)
            ->whereBetween('scheduled_at', [appNow(), appNow()->addMinutes(15)]);
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
