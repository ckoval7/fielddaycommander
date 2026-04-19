<?php

use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAssignmentForHours(array $shiftAttrs, array $assignmentAttrs = []): ShiftAssignment
{
    $config = EventConfiguration::factory()->create();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);
    $shift = Shift::factory()->create(array_merge([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
    ], $shiftAttrs));

    return ShiftAssignment::factory()->create(array_merge([
        'shift_id' => $shift->id,
        'user_id' => User::factory()->create()->id,
    ], $assignmentAttrs));
}

test('hoursWorked returns 0.0 when checked_in_at is null', function () {
    $assignment = makeAssignmentForHours([
        'start_time' => now(),
        'end_time' => now()->addHours(3),
    ], [
        'checked_in_at' => null,
        'checked_out_at' => null,
    ]);

    expect($assignment->hoursWorked())->toBe(0.0);
});

test('hoursWorked returns 0.0 when checked_out_at is null', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addHours(3),
    ], [
        'checked_in_at' => $start,
        'checked_out_at' => null,
    ]);

    expect($assignment->hoursWorked())->toBe(0.0);
});

test('hoursWorked returns actual duration when checked out early', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addHours(3),
    ], [
        'checked_in_at' => $start,
        'checked_out_at' => $start->copy()->addMinutes(90),
    ]);

    expect($assignment->hoursWorked())->toBe(1.5);
});

test('hoursWorked caps at scheduled length when checked out late', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addHours(3),
    ], [
        'checked_in_at' => $start,
        'checked_out_at' => $start->copy()->addHours(4),
    ]);

    expect($assignment->hoursWorked())->toBe(3.0);
});

test('hoursWorked returns full duration for exact-length shift', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addHours(2),
    ], [
        'checked_in_at' => $start,
        'checked_out_at' => $start->copy()->addHours(2),
    ]);

    expect($assignment->hoursWorked())->toBe(2.0);
});

test('hoursWorked rounds to 0.1 hour', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addHours(3),
    ], [
        'checked_in_at' => $start,
        // 97 minutes = 1.6166... hours -> 1.6
        'checked_out_at' => $start->copy()->addMinutes(97),
    ]);

    expect($assignment->hoursWorked())->toBe(1.6);
});

test('scheduledHours returns shift duration rounded to 0.1', function () {
    $start = now();
    $assignment = makeAssignmentForHours([
        'start_time' => $start,
        'end_time' => $start->copy()->addMinutes(150),
    ]);

    expect($assignment->scheduledHours())->toBe(2.5);
});
