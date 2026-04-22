<?php

namespace App\Models;

use App\Observers\EventObserver;
use App\Scoring\RuleSetFactory;
use Carbon\Carbon;
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
        'rules_version',
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
        return $query->where('start_time', '<=', appNow())
            ->where('end_time', '>=', appNow());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', appNow());
    }

    public function scopeCompleted($query)
    {
        return $query->where('end_time', '<', appNow());
    }

    public function scopeInProgress($query)
    {
        return $this->scopeActive($query);
    }

    public function scopeInSetupWindow($query)
    {
        return $query->whereNotNull('setup_allowed_from')
            ->where('setup_allowed_from', '<=', appNow())
            ->where('start_time', '>', appNow());
    }

    /**
     * Calculate setup_allowed_from by subtracting the given offset from the event start time.
     */
    public static function calculateSetupAllowedFrom(Carbon $startTime, int $offsetHours): Carbon
    {
        return $startTime->copy()->subHours($offsetHours);
    }

    // Accessors

    /**
     * Resolved rules_version, falling back to the event year for legacy rows.
     */
    public function getEffectiveRulesVersionAttribute(): string
    {
        return $this->rules_version ?? (string) $this->year;
    }

    /**
     * Version of the ruleset the factory actually resolves for this event.
     *
     * Differs from effective_rules_version when the requested version is not
     * yet registered — the factory falls back to the newest shipped ruleset,
     * and this accessor reflects what is really scoring the event.
     */
    public function getResolvedRulesVersionAttribute(): string
    {
        return app(RuleSetFactory::class)->forEvent($this)->version();
    }

    public function getStatusAttribute(): string
    {
        $now = appNow();

        return match (true) {
            $this->setup_allowed_from && $this->start_time && $this->setup_allowed_from <= $now && $this->start_time > $now => 'setup',
            $this->start_time && $this->start_time > $now => 'upcoming',
            $this->start_time && $this->end_time && $this->start_time <= $now && $this->end_time >= $now => 'active',
            $this->end_time && $this->end_time < $now => 'completed',
            default => 'upcoming',
        };
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'setup' => 'warning',
            'upcoming' => 'info',
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

    /**
     * Run the given callback with the rules_version lock disabled.
     *
     * Used by admin-initiated rescore flows that need to change the pinned
     * version on an event that has already started.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRulesVersionLock(callable $callback): mixed
    {
        $previous = EventObserver::$bypassRulesVersionLock;
        EventObserver::$bypassRulesVersionLock = true;

        try {
            return $callback();
        } finally {
            EventObserver::$bypassRulesVersionLock = $previous;
        }
    }
}
