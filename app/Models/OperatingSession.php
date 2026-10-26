<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\OperatingSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatingSession extends Model
{
    /** @use HasFactory<OperatingSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'operator_user_id',
        'previous_session_id',
        'band_id',
        'mode_id',
        'start_time',
        'end_time',
        'power_watts',
        'qso_count',
        'is_transcription',
        'is_supervised',
        'last_activity_at',
        'external_source',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'power_watts' => 'integer',
            'is_transcription' => 'boolean',
            'is_supervised' => 'boolean',
            'last_activity_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(Band::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }

    /**
     * The session this one replaced in a band/mode switch, if any.
     */
    public function previousSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_session_id');
    }

    /**
     * Earlier sessions this one continues through band/mode switches,
     * newest first, up to $limit. A session started fresh has none.
     *
     * @return list<int>
     */
    public function switchChainIds(int $limit = 20): array
    {
        $ids = [];
        $previousId = $this->previous_session_id;

        while ($previousId !== null && count($ids) < $limit && ! in_array($previousId, $ids, true)) {
            $ids[] = $previousId;
            $previousId = self::query()->whereKey($previousId)->value('previous_session_id');
        }

        return $ids;
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('end_time');
    }

    public function scopeForStation(Builder $query, int $stationId): Builder
    {
        return $query->where('station_id', $stationId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('operator_user_id', $userId);
    }

    public function scopeTranscription(Builder $query): Builder
    {
        return $query->where('is_transcription', true);
    }

    public function scopeSupervised(Builder $query): Builder
    {
        return $query->where('is_supervised', true);
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->end_time === null,
        );
    }

    protected function duration(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->start_time) {
                    return null;
                }

                $end = $this->end_time ?? now();
                $diff = $this->start_time->diff($end);

                if ($diff->h > 0) {
                    return $diff->h.'h '.$diff->i.'m';
                }

                return $diff->i.'m';
            },
        );
    }
}
