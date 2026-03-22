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
});
