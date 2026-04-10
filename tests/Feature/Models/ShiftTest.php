<?php

use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;

describe('Relationships', function () {
    test('belongs to event configuration', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        expect($shift->eventConfiguration)
            ->toBeInstanceOf(EventConfiguration::class)
            ->id->toBe($eventConfiguration->id);
    });

    test('belongs to shift role', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);

        expect($shift->shiftRole)
            ->toBeInstanceOf(ShiftRole::class)
            ->id->toBe($role->id);
    });

    test('has many assignments', function () {
        $shift = Shift::factory()->withCapacity(3)->create();

        ShiftAssignment::factory()->count(3)->create([
            'shift_id' => $shift->id,
        ]);

        expect($shift->assignments)->toHaveCount(3)
            ->each->toBeInstanceOf(ShiftAssignment::class);
    });
});

describe('Accessors', function () {
    test('has_capacity is true when under capacity', function () {
        $shift = Shift::factory()->withCapacity(3)->create();

        ShiftAssignment::factory()->create(['shift_id' => $shift->id]);

        expect($shift->has_capacity)->toBeTrue();
    });

    test('has_capacity is false when at capacity', function () {
        $shift = Shift::factory()->withCapacity(2)->create();

        ShiftAssignment::factory()->count(2)->create(['shift_id' => $shift->id]);

        expect($shift->has_capacity)->toBeFalse();
    });

    test('filled_count returns assignment count', function () {
        $shift = Shift::factory()->withCapacity(5)->create();

        ShiftAssignment::factory()->count(3)->create(['shift_id' => $shift->id]);

        expect($shift->filled_count)->toBe(3);
    });

    test('filled_count is zero when no assignments exist', function () {
        $shift = Shift::factory()->withCapacity(3)->create();

        expect($shift->filled_count)->toBe(0);
    });
});

describe('Time-based Accessors', function () {
    test('is_current is true when now is between start and end', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHour(),
        ]);

        expect($shift->is_current)->toBeTrue();
    });

    test('is_current is false when shift has not started', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(2),
        ]);

        expect($shift->is_current)->toBeFalse();
    });

    test('is_current is false when shift has ended', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHour(),
        ]);

        expect($shift->is_current)->toBeFalse();
    });

    test('is_past is true when shift has ended', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHour(),
        ]);

        expect($shift->is_past)->toBeTrue();
    });

    test('is_past is false when shift has not ended', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHour(),
        ]);

        expect($shift->is_past)->toBeFalse();
    });

    test('is_upcoming is true when shift has not started', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(2),
        ]);

        expect($shift->is_upcoming)->toBeTrue();
    });

    test('is_upcoming is false when shift has started', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHour(),
        ]);

        expect($shift->is_upcoming)->toBeFalse();
    });

    test('can_check_in is true within 15 minutes before start', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(10),
            'end_time' => appNow()->addHours(2),
        ]);

        expect($shift->can_check_in)->toBeTrue();
    });

    test('can_check_in is true during the shift', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subMinutes(30),
            'end_time' => appNow()->addHour(),
        ]);

        expect($shift->can_check_in)->toBeTrue();
    });

    test('can_check_in is false more than 15 minutes before start', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(30),
            'end_time' => appNow()->addHours(2),
        ]);

        expect($shift->can_check_in)->toBeFalse();
    });

    test('can_check_in is false after shift has ended', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHour(),
        ]);

        expect($shift->can_check_in)->toBeFalse();
    });
});

describe('Scopes', function () {
    test('open scope returns only open shifts', function () {
        $openShift = Shift::factory()->open()->create();
        $closedShift = Shift::factory()->create(['is_open' => false]);

        $results = Shift::query()->open()->get();

        expect($results)->toHaveCount(1)
            ->first()->id->toBe($openShift->id);
    });

    test('open scope excludes closed shifts', function () {
        Shift::factory()->count(3)->create(['is_open' => false]);

        expect(Shift::query()->open()->count())->toBe(0);
    });

    test('chronological scope orders shifts by start_time ascending', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create(['event_configuration_id' => $eventConfiguration->id]);

        $thirdShift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHours(3),
            'end_time' => appNow()->addHours(5),
        ]);
        $firstShift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(3),
        ]);
        $secondShift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
        ]);

        $orderedIds = Shift::query()
            ->forEvent($eventConfiguration->id)
            ->chronological()
            ->pluck('id')
            ->toArray();

        expect($orderedIds)->toBe([$firstShift->id, $secondShift->id, $thirdShift->id]);
    });

    test('for_event scope returns only shifts for the given event configuration', function () {
        $targetEvent = EventConfiguration::factory()->create();
        $otherEvent = EventConfiguration::factory()->create();

        $targetRole = ShiftRole::factory()->create(['event_configuration_id' => $targetEvent->id]);
        $otherRole = ShiftRole::factory()->create(['event_configuration_id' => $otherEvent->id]);

        Shift::factory()->count(2)->create([
            'event_configuration_id' => $targetEvent->id,
            'shift_role_id' => $targetRole->id,
        ]);
        Shift::factory()->create([
            'event_configuration_id' => $otherEvent->id,
            'shift_role_id' => $otherRole->id,
        ]);

        $results = Shift::query()->forEvent($targetEvent->id)->get();

        expect($results)->toHaveCount(2)
            ->each(fn ($shift) => $shift->event_configuration_id->toBe($targetEvent->id));
    });

    test('for_role scope returns only shifts for the given role', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        $targetRole = ShiftRole::factory()->create(['event_configuration_id' => $eventConfiguration->id]);
        $otherRole = ShiftRole::factory()->create(['event_configuration_id' => $eventConfiguration->id]);

        Shift::factory()->count(2)->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $targetRole->id,
        ]);
        Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $otherRole->id,
        ]);

        $results = Shift::query()->forRole($targetRole->id)->get();

        expect($results)->toHaveCount(2)
            ->each(fn ($shift) => $shift->shift_role_id->toBe($targetRole->id));
    });
});

describe('is_urgently_empty', function () {
    test('is true when shift starts within 2 hours and has no assignments', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(90),
            'end_time' => appNow()->addMinutes(210),
            'is_open' => true,
        ]);

        expect($shift->is_urgently_empty)->toBeTrue();
    });

    test('is false when shift starts within 2 hours but has at least one assignment', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(90),
            'end_time' => appNow()->addMinutes(210),
            'is_open' => true,
        ]);

        ShiftAssignment::factory()->create(['shift_id' => $shift->id]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });

    test('is false when shift starts more than 2 hours away', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addHours(3),
            'end_time' => appNow()->addHours(5),
        ]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });

    test('is false when shift is currently in progress', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subMinutes(30),
            'end_time' => appNow()->addMinutes(90),
        ]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });

    test('is false when shift has already ended', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHour(),
        ]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });

    test('is true at exactly the 2-hour boundary', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
            'is_open' => true,
        ]);

        expect($shift->is_urgently_empty)->toBeTrue();
    });

    test('is false when shift is closed even with no assignments', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(90),
            'end_time' => appNow()->addMinutes(210),
            'is_open' => false,
        ]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });

    test('is false when shift has multiple assignments', function () {
        $shift = Shift::factory()->create([
            'start_time' => appNow()->addMinutes(90),
            'end_time' => appNow()->addMinutes(210),
            'is_open' => true,
        ]);

        ShiftAssignment::factory()->count(2)->create(['shift_id' => $shift->id]);

        expect($shift->is_urgently_empty)->toBeFalse();
    });
});
