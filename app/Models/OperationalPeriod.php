<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OperationalPeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named time block on an event, such as "Day 1 AM, 0600 to 1400".
 *
 * Periods are optional. An event without any is one period from its start
 * to its end: see OperationalPeriodService::wholeEvent(), which returns an
 * unsaved period for it.
 */
class OperationalPeriod extends Model
{
    /** @use HasFactory<OperationalPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'name',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EventConfiguration, $this>
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * @param  Builder<OperationalPeriod>  $query
     * @return Builder<OperationalPeriod>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('start_time')->orderBy('id');
    }

    /**
     * Whether a moment falls in this period. The end is exclusive, so a
     * moment on the boundary belongs to the period that starts there.
     */
    public function contains(CarbonInterface $time): bool
    {
        return $this->start_time !== null
            && $this->end_time !== null
            && $time->greaterThanOrEqualTo($this->start_time)
            && $time->lessThan($this->end_time);
    }

    /**
     * Whether a span of time overlaps this period at all.
     */
    public function overlaps(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $start->lessThan($this->end_time) && $end->greaterThan($this->start_time);
    }

    /**
     * The period's times in the event's local zone, like "Oct 24 06:00 to 14:00",
     * with the end date repeated when it falls on another day.
     */
    public function timeRange(): string
    {
        if ($this->start_time === null || $this->end_time === null) {
            return '';
        }

        $start = toLocalTime($this->start_time);
        $end = toLocalTime($this->end_time);

        return $start->format('M j H:i').' to '.($start->isSameDay($end) ? $end->format('H:i') : $end->format('M j H:i'));
    }
}
