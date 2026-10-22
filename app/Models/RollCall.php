<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\RollCallFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A roll call (a Personnel Accountability Report, in ICS terms) on a net.
 *
 * Starting one takes a snapshot of the stations expected to answer. Each is
 * accounted for when net control logs its answer. The start, the answers and
 * the result all go in the net log, so they show on the ICS-309.
 */
class RollCall extends Model
{
    /** @use HasFactory<RollCallFactory> */
    use HasFactory;

    protected $fillable = [
        'net_id',
        'user_id',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function net(): BelongsTo
    {
        return $this->belongsTo(Net::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(RollCallStation::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @param  Builder<RollCall>  $query
     * @return Builder<RollCall>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
