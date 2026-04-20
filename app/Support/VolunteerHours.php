<?php

namespace App\Support;

use App\Models\ShiftAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aggregation helpers for a volunteer's collection of shift assignments.
 *
 * Callers must pass a Collection<ShiftAssignment> with the `shift` relation eager-loaded
 * and no-show assignments already filtered out.
 */
class VolunteerHours
{
    public const MODE_SUM = 'sum';

    public const MODE_WALL_CLOCK = 'wall_clock';

    /**
     * Static-only utility class; instantiation is disallowed.
     */
    private function __construct() {}

    /**
     * Sum each assignment's clamped hoursWorked (duplicates overlapping time across roles).
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    public static function sumHoursWorked(Collection $assignments): float
    {
        return round($assignments->sum(fn (ShiftAssignment $a) => $a->hoursWorked()), 1);
    }

    /**
     * Sum each assignment's full scheduled length (duplicates overlapping time across roles).
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    public static function sumHoursScheduled(Collection $assignments): float
    {
        return round($assignments->sum(fn (ShiftAssignment $a) => $a->scheduledHours()), 1);
    }

    /**
     * Merge overlapping check-in/check-out intervals (clamped to each shift window)
     * and return the total wall-clock hours on site, rounded to 0.1.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    public static function wallClockHoursWorked(Collection $assignments): float
    {
        $intervals = $assignments
            ->filter(fn (ShiftAssignment $a) => $a->checked_in_at !== null && $a->checked_out_at !== null)
            ->map(function (ShiftAssignment $a) {
                $start = $a->checked_in_at->max($a->shift->start_time);
                $end = $a->checked_out_at->min($a->shift->end_time);

                return [$start, $end];
            })
            ->filter(fn (array $pair) => $pair[1] > $pair[0]);

        return self::mergeAndSum($intervals);
    }

    /**
     * Merge overlapping scheduled shift windows and return total wall-clock hours, rounded to 0.1.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    public static function wallClockHoursScheduled(Collection $assignments): float
    {
        $intervals = $assignments
            ->map(fn (ShiftAssignment $a) => [$a->shift->start_time, $a->shift->end_time])
            ->filter(fn (array $pair) => $pair[1] > $pair[0]);

        return self::mergeAndSum($intervals);
    }

    /**
     * Merge a collection of [start, end] Carbon pairs and return total hours (rounded to 0.1).
     *
     * Touching intervals (end == next start) are merged into one.
     *
     * @param  Collection<int, array{0: CarbonInterface, 1: CarbonInterface}>  $intervals
     */
    private static function mergeAndSum(Collection $intervals): float
    {
        if ($intervals->isEmpty()) {
            return 0.0;
        }

        $sorted = $intervals->sortBy(fn (array $pair) => $pair[0]->getTimestamp())->values();

        $merged = [];
        [$currentStart, $currentEnd] = $sorted->first();

        foreach ($sorted->slice(1) as [$start, $end]) {
            if ($start <= $currentEnd) {
                if ($end > $currentEnd) {
                    $currentEnd = $end;
                }
            } else {
                $merged[] = [$currentStart, $currentEnd];
                $currentStart = $start;
                $currentEnd = $end;
            }
        }
        $merged[] = [$currentStart, $currentEnd];

        $totalMinutes = array_sum(array_map(
            fn (array $pair) => $pair[0]->diffInMinutes($pair[1]),
            $merged,
        ));

        return round($totalMinutes / 60, 1);
    }
}
