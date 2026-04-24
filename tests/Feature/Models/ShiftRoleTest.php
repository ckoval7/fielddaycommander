<?php

use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftRole;

describe('Relationships', function () {
    test('belongs to event configuration', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        expect($role->eventConfiguration)
            ->toBeInstanceOf(EventConfiguration::class)
            ->id->toBe($eventConfiguration->id);
    });

    test('has many shifts', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        Shift::factory()->count(3)->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);

        expect($role->shifts)->toHaveCount(3)
            ->each->toBeInstanceOf(Shift::class);
    });
});

describe('Scopes', function () {
    test('forEvent filters by event configuration id', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        ShiftRole::factory()->count(2)->create(['event_configuration_id' => $event1->id]);
        ShiftRole::factory()->create(['event_configuration_id' => $event2->id]);

        $filtered = ShiftRole::forEvent($event1->id)->get();

        expect($filtered)->toHaveCount(2)
            ->each(fn ($role) => $role->event_configuration_id->toBe($event1->id));
    });

    test('default scope returns only default roles', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        ShiftRole::factory()->default()->create(['event_configuration_id' => $eventConfiguration->id]);
        ShiftRole::factory()->create(['event_configuration_id' => $eventConfiguration->id, 'is_default' => false]);

        $defaults = ShiftRole::forEvent($eventConfiguration->id)->default()->get();

        expect($defaults)->toHaveCount(1)
            ->each(fn ($role) => $role->is_default->toBeTrue());
    });

    test('custom scope returns only non-default roles', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        ShiftRole::factory()->default()->create(['event_configuration_id' => $eventConfiguration->id]);
        ShiftRole::factory()->create(['event_configuration_id' => $eventConfiguration->id, 'is_default' => false]);

        $custom = ShiftRole::forEvent($eventConfiguration->id)->custom()->get();

        expect($custom)->toHaveCount(1)
            ->each(fn ($role) => $role->is_default->toBeFalse());
    });
});

describe('Seeding', function () {
    test('seedDefaults creates correct roles for Class A', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        ShiftRole::seedDefaults($eventConfiguration);

        $roles = ShiftRole::forEvent($eventConfiguration->id)->pluck('name')->toArray();

        expect($roles)
            ->toContain('Safety Officer')
            ->toContain('Public Information Table')
            ->toContain('Public Greeter')
            ->toContain('GOTA Coach')
            ->toContain('Message Handler')
            ->toContain('Event Manager')
            ->toContain('Station Captain')
            ->not->toContain('Site Responsibilities');
    });

    test('seedDefaults does not duplicate on re-run', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        ShiftRole::seedDefaults($eventConfiguration);
        $countAfterFirst = ShiftRole::forEvent($eventConfiguration->id)->count();

        ShiftRole::seedDefaults($eventConfiguration);
        $countAfterSecond = ShiftRole::forEvent($eventConfiguration->id)->count();

        expect($countAfterSecond)->toBe($countAfterFirst);
    });

    test('getBonusTypeCode returns codes for auto-award roles only', function () {
        $eventConfiguration = EventConfiguration::factory()->create();

        $pioTable = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'name' => 'Public Information Table',
        ]);
        expect($pioTable->getBonusTypeCode())->toBe('public_info_booth');
    });

    test('getBonusTypeCode returns null for eligibility-only roles', function () {
        $safetyOfficer = ShiftRole::factory()->safetyOfficer()->create();
        expect($safetyOfficer->getBonusTypeCode())->toBeNull();

        $siteResp = ShiftRole::factory()->create(['name' => 'Site Responsibilities']);
        expect($siteResp->getBonusTypeCode())->toBeNull();

        $gotaCoach = ShiftRole::factory()->create(['name' => 'GOTA Coach']);
        expect($gotaCoach->getBonusTypeCode())->toBeNull();

        $publicGreeter = ShiftRole::factory()->create(['name' => 'Public Greeter']);
        expect($publicGreeter->getBonusTypeCode())->toBeNull();
    });

    test('isBonusEligibilityOnly identifies eligibility-only roles', function () {
        $safetyOfficer = ShiftRole::factory()->safetyOfficer()->create();
        expect($safetyOfficer->isBonusEligibilityOnly())->toBeTrue();
        expect($safetyOfficer->getBonusEligibilityRequirement())->toContain('Checklist');

        $pioTable = ShiftRole::factory()->create(['name' => 'Public Information Table']);
        expect($pioTable->isBonusEligibilityOnly())->toBeFalse();

        $custom = ShiftRole::factory()->create(['name' => 'Food Coordinator']);
        expect($custom->isBonusEligibilityOnly())->toBeFalse();
    });
});
