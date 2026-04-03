<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestbookEntry extends Model
{
    /** @use HasFactory<\Database\Factories\GuestbookEntryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Presence type constants.
     */
    public const PRESENCE_TYPE_IN_PERSON = 'in_person';

    public const PRESENCE_TYPE_ONLINE = 'online';

    public const PRESENCE_TYPES = [
        self::PRESENCE_TYPE_IN_PERSON,
        self::PRESENCE_TYPE_ONLINE,
    ];

    /**
     * Visitor category constants.
     */
    public const VISITOR_CATEGORY_ELECTED_OFFICIAL = 'elected_official';

    public const VISITOR_CATEGORY_ARRL_OFFICIAL = 'arrl_official';

    public const VISITOR_CATEGORY_AGENCY = 'agency';

    public const VISITOR_CATEGORY_MEDIA = 'media';

    public const VISITOR_CATEGORY_ARES_RACES = 'ares_races';

    public const VISITOR_CATEGORY_HAM_CLUB = 'ham_club';

    public const VISITOR_CATEGORY_YOUTH = 'youth';

    public const VISITOR_CATEGORY_GENERAL_PUBLIC = 'general_public';

    public const VISITOR_CATEGORIES = [
        self::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        self::VISITOR_CATEGORY_ARRL_OFFICIAL,
        self::VISITOR_CATEGORY_AGENCY,
        self::VISITOR_CATEGORY_MEDIA,
        self::VISITOR_CATEGORY_ARES_RACES,
        self::VISITOR_CATEGORY_HAM_CLUB,
        self::VISITOR_CATEGORY_YOUTH,
        self::VISITOR_CATEGORY_GENERAL_PUBLIC,
    ];

    /**
     * Bonus-eligible visitor categories.
     */
    public const BONUS_ELIGIBLE_CATEGORIES = [
        self::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        self::VISITOR_CATEGORY_AGENCY,
        self::VISITOR_CATEGORY_MEDIA,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_configuration_id',
        'user_id',
        'callsign',
        'first_name',
        'last_name',
        'email',
        'comments',
        'ip_address',
        'presence_type',
        'visitor_category',
        'is_verified',
        'verified_by',
        'verified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presence_type' => 'string',
            'visitor_category' => 'string',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Get the event configuration this entry belongs to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the user who signed the guestbook (if logged in).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who verified this entry.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scope to only include verified entries.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to only include unverified entries.
     */
    public function scopeUnverified(Builder $query): Builder
    {
        return $query->where('is_verified', false);
    }

    /**
     * Scope to only include in-person entries.
     */
    public function scopeInPerson(Builder $query): Builder
    {
        return $query->where('presence_type', self::PRESENCE_TYPE_IN_PERSON);
    }

    /**
     * Scope to only include online entries.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('presence_type', self::PRESENCE_TYPE_ONLINE);
    }

    /**
     * Scope to only include bonus-eligible entries.
     */
    public function scopeBonusEligible(Builder $query): Builder
    {
        return $query->whereIn('visitor_category', self::BONUS_ELIGIBLE_CATEGORIES);
    }

    /**
     * Check if this entry's visitor category is bonus-eligible.
     */
    public function getIsBonusEligibleAttribute(): bool
    {
        return in_array($this->visitor_category, self::BONUS_ELIGIBLE_CATEGORIES, true);
    }
}
