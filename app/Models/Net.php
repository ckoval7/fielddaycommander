<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\NetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A directed net run during an event, such as the local 2m net or a
 * regional HF net. Several can run at once, each with its own log.
 *
 * Nets can be planned before the event or started by net control on the day.
 */
class Net extends Model
{
    /** @use HasFactory<NetFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'user_id',
        'name',
        'frequency',
        'band_id',
        'mode_id',
        'net_control_station',
        'overdue_after_minutes',
    ];

    protected function casts(): array
    {
        return [
            'overdue_after_minutes' => 'integer',
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(Band::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(NetLogEntry::class);
    }

    public function rollCalls(): HasMany
    {
        return $this->hasMany(RollCall::class);
    }

    /**
     * @param  Builder<Net>  $query
     * @return Builder<Net>
     */
    public function scopeForEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('event_configuration_id', $eventConfigurationId);
    }

    /**
     * Frequency, band and mode as one line, e.g. "146.520 MHz · 2m · FM".
     */
    public function details(): string
    {
        return collect([$this->frequency, $this->band?->name, $this->mode?->name])
            ->filter(fn (?string $part) => $part !== null && $part !== '')
            ->implode(' · ');
    }
}
