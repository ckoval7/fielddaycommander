<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\NetPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One exchange on a directed net: who called whom, when, and what was said.
 *
 * Unlike a Contact there is no exchange, scoring or duplicate check; the same
 * positions talk to each other all event. The FCC callsigns are a snapshot of
 * who was operating each tactical position when the entry was logged.
 */
class NetLogEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'user_id',
        'logged_at',
        'from_station',
        'to_station',
        'from_callsign',
        'to_callsign',
        'message',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'priority' => NetPriority::class,
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

    /**
     * @param  Builder<NetLogEntry>  $query
     * @return Builder<NetLogEntry>
     */
    public function scopeForEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('event_configuration_id', $eventConfigurationId);
    }

    /**
     * A station as the log shows it: the tactical name, with the licensed
     * operator's callsign when it differs, e.g. "Aid 7 (W1ABC)".
     */
    public static function displayStation(?string $station, ?string $callsign): string
    {
        if ($station === null || $station === '') {
            return $callsign ?? '';
        }

        if ($callsign === null || $callsign === '' || strcasecmp($station, $callsign) === 0) {
            return $station;
        }

        return "{$station} ({$callsign})";
    }
}
