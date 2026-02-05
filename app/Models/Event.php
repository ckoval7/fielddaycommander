<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'event_type_id',
        'year',
        'start_time',
        'end_time',
        'setup_allowed_from',
        'max_setup_hours',
        'is_active',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'setup_allowed_from' => 'datetime',
            'is_active' => 'boolean',
            'is_current' => 'boolean',
        ];
    }

    // Relationships
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function eventConfiguration(): HasOne
    {
        return $this->hasOne(EventConfiguration::class);
    }

    public function contacts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Contact::class,
            EventConfiguration::class,
            'event_id',
            'event_configuration_id'
        );
    }

    // Scopes
    public function scopeActive($query)
    {
        $activeEventId = Setting::get('active_event_id');

        return $query->where('id', $activeEventId);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now());
    }

    public function scopeCompleted($query)
    {
        return $query->where('end_time', '<', now());
    }

    public function scopeInProgress($query)
    {
        return $query->where('start_time', '<=', now())
            ->where('end_time', '>=', now());
    }

    // Accessors
    public function getStatusAttribute(): string
    {
        if ($this->id == Setting::get('active_event_id')) {
            return 'active';
        } elseif ($this->start_time && $this->start_time > appNow()) {
            return 'upcoming';
        } elseif ($this->end_time && $this->end_time < appNow()) {
            return 'completed';
        } else {
            return 'in_progress';
        }
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'upcoming' => 'info',
            'in_progress' => 'warning',
            'completed' => 'neutral',
        };
    }

    public function getContactsCountAttribute(): int
    {
        return $this->eventConfiguration?->contacts()->count() ?? 0;
    }

    public function getParticipantsCountAttribute(): int
    {
        return $this->eventConfiguration?->contacts()->distinct('logger_user_id')->count('logger_user_id') ?? 0;
    }

    public function getFinalScoreAttribute(): int
    {
        return $this->eventConfiguration?->calculateFinalScore() ?? 0;
    }
}
