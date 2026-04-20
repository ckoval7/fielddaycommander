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

test('wallClockHoursWorked returns 0.0 for an empty collection', function () {
    expect(VolunteerHours::wallClockHoursWorked(collect()))->toBe(0.0);
});

test('wallClockHoursWorked equals sum when intervals do not overlap', function () {
    $base = now()->startOfHour();
    $a = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(2)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base,
            'checked_out_at' => $base->copy()->addHours(2)],
    );
    $b = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(3), 'end_time' => $base->copy()->addHours(5)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHours(3),
            'checked_out_at' => $base->copy()->addHours(5)],
    );

    expect(VolunteerHours::wallClockHoursWorked(collect([$a, $b])))->toBe(4.0);
});

test('wallClockHoursWorked merges partially overlapping intervals', function () {
    // 10-14 Event Manager, 11-13 Safety Officer, 12-13 Operator → 4 wall-clock hours.
    $base = now()->startOfDay()->addHours(10);
    $em = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(4)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base,
            'checked_out_at' => $base->copy()->addHours(4)],
    );
    $safety = vhMakeAssignment(
        ['start_time' => $base->copy()->addHour(), 'end_time' => $base->copy()->addHours(3)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHour(),
            'checked_out_at' => $base->copy()->addHours(3)],
    );
    $op = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(2), 'end_time' => $base->copy()->addHours(3)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHours(2),
            'checked_out_at' => $base->copy()->addHours(3)],
    );

    expect(VolunteerHours::wallClockHoursWorked(collect([$em, $safety, $op])))->toBe(4.0);
});

test('wallClockHoursWorked treats back-to-back intervals as a single merged interval', function () {
    $base = now()->startOfHour();
    $a = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHour()],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base,
            'checked_out_at' => $base->copy()->addHour()],
    );
    $b = vhMakeAssignment(
        ['start_time' => $base->copy()->addHour(), 'end_time' => $base->copy()->addHours(2)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHour(),
            'checked_out_at' => $base->copy()->addHours(2)],
    );

    expect(VolunteerHours::wallClockHoursWorked(collect([$a, $b])))->toBe(2.0);
});

test('wallClockHoursWorked clamps early check-in and late check-out to the shift window', function () {
    $base = now()->startOfHour();
    // Shift 12-14, but user checked in 11 and left 15 → clamp to 12-14 = 2h.
    $a = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(2), 'end_time' => $base->copy()->addHours(4)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base->copy()->addHour(),
            'checked_out_at' => $base->copy()->addHours(5)],
    );

    expect(VolunteerHours::wallClockHoursWorked(collect([$a])))->toBe(2.0);
});

test('wallClockHoursWorked skips assignments missing check-in or check-out', function () {
    $base = now()->startOfHour();
    $good = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(2)],
        ['status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => $base,
            'checked_out_at' => $base->copy()->addHours(2)],
    );
    $noCheckout = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(3), 'end_time' => $base->copy()->addHours(5)],
        ['status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => $base->copy()->addHours(3),
            'checked_out_at' => null],
    );

    expect(VolunteerHours::wallClockHoursWorked(collect([$good, $noCheckout])))->toBe(2.0);
});

test('wallClockHoursScheduled merges overlapping signup windows', function () {
    // Same 10-14 / 11-13 / 12-13 signups → 4 scheduled wall-clock hours.
    $base = now()->startOfDay()->addHours(10);
    $em = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(4)],
    );
    $safety = vhMakeAssignment(
        ['start_time' => $base->copy()->addHour(), 'end_time' => $base->copy()->addHours(3)],
    );
    $op = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(2), 'end_time' => $base->copy()->addHours(3)],
    );

    expect(VolunteerHours::wallClockHoursScheduled(collect([$em, $safety, $op])))->toBe(4.0);
});

test('wallClockHoursScheduled equals sum when signups do not overlap', function () {
    $base = now()->startOfHour();
    $a = vhMakeAssignment(
        ['start_time' => $base, 'end_time' => $base->copy()->addHours(2)],
    );
    $b = vhMakeAssignment(
        ['start_time' => $base->copy()->addHours(3), 'end_time' => $base->copy()->addHours(5)],
    );

    expect(VolunteerHours::wallClockHoursScheduled(collect([$a, $b])))->toBe(4.0);
});
