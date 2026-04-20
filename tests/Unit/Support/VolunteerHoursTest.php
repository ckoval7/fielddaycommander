<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\VolunteerHours;

function vhMakeAssignment(array $shiftAttrs, array $assignmentAttrs = []): ShiftAssignment
{
    $shift = Shift::factory()->create($shiftAttrs);

    return ShiftAssignment::factory()->create(array_merge(
        ['shift_id' => $shift->id],
        $assignmentAttrs,
    ))->load('shift');
}

test('sumHoursWorked returns 0.0 for an empty collection', function () {
    expect(VolunteerHours::sumHoursWorked(collect()))->toBe(0.0);
});

test('sumHoursWorked adds every assignment regardless of overlap', function () {
    $base = now()->startOfHour();
    $a = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(4)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base,
            'checked_out_at' => $base->copy()->addHours(4)],
    );
    $b = vhMakeAssignment(
        ['start_time' => $base->copy()->addHour(), 'end_time' => $base->copy()->addHours(3)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHour(),
            'checked_out_at' => $base->copy()->addHours(3)],
    );

    expect(VolunteerHours::sumHoursWorked(collect([$a, $b])))->toBe(6.0);
});

test('sumHoursScheduled sums every assignment scheduled duration', function () {
    $base = now()->startOfHour();
    $a = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(4)],
    );
    $b = vhMakeAssignment(
        ['start_time' => $base->copy()->addHour(), 'end_time' => $base->copy()->addHours(3)],
    );

    expect(VolunteerHours::sumHoursScheduled(collect([$a, $b])))->toBe(6.0);
});
