<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use Database\Factories\GuestbookEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestbookEntry extends Model
{
    /** @use HasFactory<GuestbookEntryFactory> */
    use HasFactory, SoftDeletes;

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
            'presence_type' => PresenceType::class,
            'visitor_category' => VisitorCategory::class,
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
        return $query->where('presence_type', PresenceType::InPerson);
    }

    /**
     * Scope to only include online entries.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('presence_type', PresenceType::Online);
    }

    /**
     * Scope to only include bonus-eligible entries.
     */
    public function scopeBonusEligible(Builder $query): Builder
    {
        return $query->whereIn('visitor_category', VisitorCategory::bonusEligible());
    }

    /**
     * Check if this entry's visitor category is bonus-eligible.
     */
    public function getIsBonusEligibleAttribute(): bool
    {
        return $this->visitor_category?->isBonusEligible() ?? false;
    }

    /**
     * Message shown when an online entry can't be verified because its category needs an on-site visit.
     */
    public const string IN_PERSON_REQUIRED_MESSAGE = 'Agency and elected official visits must be in person to count toward the bonus. Change the category, or leave this entry unverified.';

    /**
     * Whether an entry with this category and presence may be verified.
     *
     * Online entries can't be verified in a category whose bonus requires an on-site visit.
     */
    public static function canBeVerifiedAs(?VisitorCategory $category, ?PresenceType $presence): bool
    {
        return ! ($presence === PresenceType::Online && $category?->requiresInPersonVisit());
    }
}
