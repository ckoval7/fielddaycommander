<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\VisitorCategory;
use App\Observers\EventObserver;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\RuleSetFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Seconds the first-contact time stays cached. Contact writes clear it
     * (see ContactObserver); the TTL only covers bulk deletes that skip it.
     */
    private const FIRST_CONTACT_CACHE_SECONDS = 600;

    /**
     * Seconds this instance reuses its looked-up first-contact time, so a bulk
     * rescore asks the cache once rather than per contact, while a model held
     * by a long-running listener still sees a first contact logged later.
     */
    private const FIRST_CONTACT_MEMO_SECONDS = 5;

    /**
     * @var array{value: ?Carbon, at: int}|null
     */
    private ?array $firstContactMemo = null;

    protected $fillable = [
        'name',
        'event_type_id',
        'year',
        'rules_version',
        'start_time',
        'end_time',
        'setup_allowed_from',
        'max_setup_hours',
        'setup_started_early',
        'is_active',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'setup_allowed_from' => 'datetime',
            'setup_started_early' => 'boolean',
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

    /**
     * Events that are in progress or still to come: anything equipment can
     * be committed to.
     */
    public function scopeNotEnded($query)
    {
        return $query->where('end_time', '>=', appNow());
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

    /**
     * Field Day classes that may operate the whole 27-hour period when they
     * wait for the start to begin setting up. Rule 3.1 names A and B; Class F
     * (an EOC activated by a group) is treated like A, as the rulebook ties
     * F to A's rules without saying so for the operating period.
     *
     * @var list<string>
     */
    public const FULL_PERIOD_CLASSES = ['A', 'B', 'F'];

    /**
     * Whether this Field Day station may operate only 24 consecutive hours
     * from its first contact: it started setup before the contest (rule 3.2),
     * or its class is not one that rule 3.1 allows the full 27 hours.
     */
    public function isLimitedToTwentyFourHours(): bool
    {
        if ($this->setup_started_early) {
            return true;
        }

        if ($this->eventType?->code !== 'FD') {
            return false;
        }

        $classCode = $this->eventConfiguration?->operatingClass?->code;

        return $classCode !== null && ! in_array($classCode, self::FULL_PERIOD_CLASSES, true);
    }

    /**
     * When contacts stop counting: the end of the operating period.
     *
     * Normally the event end. A Field Day station limited to 24 consecutive
     * hours (see isLimitedToTwentyFourHours()) stops at the earlier of the
     * event end and 24 hours after its first contact, once that contact
     * exists. Event lifecycle (active, grace, archived) always follows the
     * real end_time.
     */
    public function operatingEndsAt(): ?Carbon
    {
        if ($this->end_time === null || ! $this->isLimitedToTwentyFourHours()) {
            return $this->end_time;
        }

        $firstContactAt = $this->firstContactAt();

        if ($firstContactAt === null) {
            return $this->end_time;
        }

        return $firstContactAt->copy()->addHours(24)->min($this->end_time);
    }

    /**
     * Whether a QSO time falls inside the start time and operating end,
     * inclusive. An event with no window set accepts every time.
     */
    public function isWithinOperatingPeriod(Carbon $time): bool
    {
        $end = $this->operatingEndsAt();

        if ($this->start_time === null || $end === null) {
            return true;
        }

        return $time->between($this->start_time, $end);
    }

    /**
     * Time of the event's earliest live contact at or after the start, from
     * any station including GOTA. Null before the first contact.
     *
     * Cached per event configuration and cleared whenever a contact is
     * written, so hot paths don't run the MIN query on every request.
     */
    public function firstContactAt(): ?Carbon
    {
        if ($this->start_time === null) {
            return null;
        }

        if ($this->firstContactMemo !== null && time() - $this->firstContactMemo['at'] < self::FIRST_CONTACT_MEMO_SECONDS) {
            return $this->firstContactMemo['value'];
        }

        $configurationId = $this->eventConfiguration?->id;
        $value = null;

        if ($configurationId !== null) {
            $timestamp = Cache::remember(
                self::firstContactCacheKey($configurationId),
                self::FIRST_CONTACT_CACHE_SECONDS,
                fn (): int => ($first = Contact::query()
                    ->where('event_configuration_id', $configurationId)
                    ->where('qso_time', '>=', $this->start_time)
                    ->min('qso_time')) === null ? 0 : Carbon::parse($first)->getTimestamp(),
            );

            $value = $timestamp > 0 ? Carbon::createFromTimestamp($timestamp, 'UTC') : null;
        }

        $this->firstContactMemo = ['value' => $value, 'at' => time()];

        return $value;
    }

    /**
     * Drop the cached first-contact time after a contact for this
     * configuration is added, moved, deleted or restored.
     */
    public static function forgetFirstContactAt(int $eventConfigurationId): void
    {
        Cache::forget(self::firstContactCacheKey($eventConfigurationId));
    }

    private static function firstContactCacheKey(int $eventConfigurationId): string
    {
        return "event-configuration:{$eventConfigurationId}:first-contact-at";
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

    /**
     * The terminology of the rulebook this event is scored under.
     *
     * Falls back to Field Day wording for an event with no type yet, so
     * labels render before the event is fully configured.
     */
    public function nomenclature(): Nomenclature
    {
        if (! $this->loadMissing('eventType')->eventType) {
            return new Nomenclature;
        }

        return app(RuleSetFactory::class)->forEvent($this)->nomenclature();
    }

    /**
     * Whether this event's rulebook awards points for message traffic.
     *
     * ARRL Field Day scores the SM/SEC message and NTS traffic; Winter Field
     * Day does not, so message handling is tracked there without points.
     * Assumes Field Day for an event with no type yet.
     */
    public function scoresMessageBonuses(): bool
    {
        if (! $this->loadMissing('eventType')->eventType) {
            return true;
        }

        return app(RuleSetFactory::class)->forEvent($this)->bonusRuleReference('sm_sec_message') !== null;
    }

    /**
     * Visitor categories whose verified visits earn a bonus under this event's rulebook.
     *
     * ARRL Field Day awards elected official and served agency visits; Winter
     * Field Day does not, so the guestbook tracks those visitors without points.
     * Assumes Field Day for an event with no type yet.
     *
     * @return list<VisitorCategory>
     */
    public function guestbookBonusCategories(): array
    {
        if (! $this->loadMissing('eventType')->eventType) {
            return VisitorCategory::bonusEligible();
        }

        $rules = app(RuleSetFactory::class)->forEvent($this);

        return array_values(array_filter(
            VisitorCategory::bonusEligible(),
            fn (VisitorCategory $category) => $rules->bonusRuleReference($category->bonusCode()) !== null,
        ));
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
        return EventObserver::withoutRulesVersionLock($callback);
    }
}
