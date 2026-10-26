<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\EventConfiguration;
use App\Models\OperationalPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out which operational period a moment belongs to.
 *
 * Periods are optional. An event without any is treated as one period that
 * runs from the event's start to its end, returned as an unsaved period so
 * callers can read its name and times the same way. An unsaved period
 * filters nothing: everything at the event falls in it.
 */
class OperationalPeriodService
{
    public const WHOLE_EVENT_NAME = 'Whole event';

    /**
     * The event's saved periods, in time order.
     *
     * @return Collection<int, OperationalPeriod>
     */
    public function periods(EventConfiguration $config): Collection
    {
        return $config->operationalPeriods()->ordered()->get();
    }

    /**
     * The event's periods, or the whole event as one period when it has none.
     *
     * @return Collection<int, OperationalPeriod>
     */
    public function periodsOrWholeEvent(EventConfiguration $config): Collection
    {
        $periods = $this->periods($config);

        return $periods->isEmpty() ? collect([$this->wholeEvent($config)]) : $periods;
    }

    /**
     * An unsaved period covering the whole event.
     */
    public function wholeEvent(EventConfiguration $config): OperationalPeriod
    {
        $event = $config->event;

        return new OperationalPeriod([
            'event_configuration_id' => $config->id,
            'name' => self::WHOLE_EVENT_NAME,
            'start_time' => $event?->start_time,
            'end_time' => $event?->end_time,
        ]);
    }

    /**
     * The saved period a moment falls in, if any.
     */
    public function at(EventConfiguration $config, CarbonInterface $time): ?OperationalPeriod
    {
        return $config->operationalPeriods()
            ->where('start_time', '<=', $time)
            ->where('end_time', '>', $time)
            ->ordered()
            ->first();
    }

    /**
     * The period to show by default: the one under way now, else the next
     * to start, else the last. The whole event when there are no periods.
     */
    public function current(EventConfiguration $config): OperationalPeriod
    {
        $now = appNow();
        $periods = $this->periods($config);

        return $periods->first(fn (OperationalPeriod $period) => $period->contains($now))
            ?? $periods->first(fn (OperationalPeriod $period) => $period->start_time->greaterThan($now))
            ?? $periods->last()
            ?? $this->wholeEvent($config);
    }

    /**
     * A saved period on this event by id, or the default period when the id
     * is missing or belongs to another event.
     */
    public function resolve(EventConfiguration $config, ?int $periodId): OperationalPeriod
    {
        $period = $periodId === null ? null : $config->operationalPeriods()->find($periodId);

        return $period ?? $this->current($config);
    }
}
