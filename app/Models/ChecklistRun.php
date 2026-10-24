<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\ChecklistRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pass through a checklist for one subject: the event, a station or a
 * person. The subject key keeps each subject to a single run.
 */
class ChecklistRun extends Model
{
    /** @use HasFactory<ChecklistRunFactory> */
    use HasFactory;

    public const EVENT_SUBJECT = 'event';

    protected $fillable = [
        'checklist_id',
        'subject_key',
        'station_id',
        'user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Checklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChecklistAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ChecklistAnswer::class);
    }

    /**
     * Subject key for a station's run.
     */
    public static function stationKey(int $stationId): string
    {
        return "station:{$stationId}";
    }

    /**
     * Subject key for a person's run.
     */
    public static function userKey(int $userId): string
    {
        return "user:{$userId}";
    }

    /**
     * Name of what this run covers, for headings and logs.
     */
    public function subjectName(): string
    {
        return match (true) {
            $this->station_id !== null => $this->station?->name ?? 'Station',
            $this->user_id !== null => $this->user?->call_sign ?? 'Person',
            default => $this->checklist?->eventConfiguration?->event?->name ?? 'Event',
        };
    }
}
