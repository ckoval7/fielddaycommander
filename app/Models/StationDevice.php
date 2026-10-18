<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\StationDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser enrolled by a station captain to run station mode for one station.
 */
class StationDevice extends Model
{
    /** @use HasFactory<StationDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'token_hash',
        'name',
        'enrolled_by_user_id',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the station this browser is bound to.
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * Get the user who enrolled this browser.
     */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    /**
     * Scope to browsers that have not been revoked.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Hash a raw device token for storage and lookup.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Check whether this browser has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
