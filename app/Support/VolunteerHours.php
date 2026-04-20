<?php

namespace App\Support;

use App\Models\ShiftAssignment;
use Illuminate\Support\Collection;

/**
 * Aggregation helpers for a volunteer's collection of shift assignments.
 *
 * Callers must pass a Collection<ShiftAssignment> with the `shift` relation eager-loaded
 * and no-show assignments already filtered out.
 */
class VolunteerHours
{
    /**
     * Sum each assignment's clamped hoursWorked (duplicates overlapping time across roles).
     */
    public static function sumHoursWorked(Collection $assignments): float
    {
        return round($assignments->sum(fn (ShiftAssignment $a) => $a->hoursWorked()), 1);
    }

    /**
     * Sum each assignment's full scheduled length (duplicates overlapping time across roles).
     */
    public static function sumHoursScheduled(Collection $assignments): float
    {
        return round($assignments->sum(fn (ShiftAssignment $a) => $a->scheduledHours()), 1);
    }
}
