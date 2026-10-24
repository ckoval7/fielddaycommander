<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\EventConfiguration;
use App\Models\OperatingClass;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Services\ChecklistService;
use App\Support\ChecklistTemplates;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->service = app(ChecklistService::class);
    $this->eventConfig = EventConfiguration::factory()->publicService()->create();
    $this->volunteer = User::factory()->create();
    $this->stranger = User::factory()->create();

    ShiftAssignment::factory()->create([
        'shift_id' => Shift::factory()->create(['event_configuration_id' => $this->eventConfig->id])->id,
        'user_id' => $this->volunteer->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('manage-shifts');
});

describe('who can answer', function () {
    test('an open checklist takes anyone with a shift and schedule managers', function () {
        $run = ChecklistRun::factory()->create(['checklist_id' => Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id])->id]);

        expect($this->service->canAnswer($this->volunteer, $run))->toBeTrue()
            ->and($this->service->canAnswer($this->manager, $run))->toBeTrue()
            ->and($this->service->canAnswer($this->stranger, $run))->toBeFalse();
    });

    test('a no-show does not count as a shift', function () {
        ShiftAssignment::where('user_id', $this->volunteer->id)->update(['status' => ShiftAssignment::STATUS_NO_SHOW]);
        $run = ChecklistRun::factory()->create(['checklist_id' => Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id])->id]);

        expect($this->service->canAnswer($this->volunteer, $run))->toBeFalse();
    });

    test('a checklist limited to a shift role takes only that role, managers included', function () {
        $role = ShiftRole::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $checklist->shiftRoles()->attach($role);
        $run = ChecklistRun::factory()->create(['checklist_id' => $checklist->id]);

        $officer = User::factory()->create();
        ShiftAssignment::factory()->create([
            'shift_id' => Shift::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'shift_role_id' => $role->id])->id,
            'user_id' => $officer->id,
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
        ]);

        expect($this->service->canAnswer($officer, $run))->toBeTrue()
            ->and($this->service->canAnswer($this->volunteer, $run))->toBeFalse()
            ->and($this->service->canAnswer($this->manager, $run))->toBeFalse();
    });

    test('anyone can fill in their own per-person checklist', function () {
        $checklist = Checklist::factory()->perPerson()->create(['event_configuration_id' => $this->eventConfig->id]);

        expect($this->service->canAnswer($this->stranger, $this->service->runFor($checklist, user: $this->stranger)))->toBeTrue()
            ->and($this->service->canAnswer($this->stranger, $this->service->runFor($checklist, user: $this->volunteer)))->toBeFalse();
    });
});

test('one run per subject', function () {
    $checklist = Checklist::factory()->perStation()->create(['event_configuration_id' => $this->eventConfig->id]);
    $station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    $first = $this->service->runFor($checklist, $station);

    expect($this->service->runFor($checklist, $station)->id)->toBe($first->id)
        ->and($first->subject_key)->toBe("station:{$station->id}");
});

test('people on a per-person checklist are the event\'s volunteers and anyone already started', function () {
    $checklist = Checklist::factory()->perPerson()->create(['event_configuration_id' => $this->eventConfig->id]);
    $this->service->runFor($checklist, user: $this->stranger);

    expect($this->service->peopleFor($checklist)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->volunteer->id, $this->stranger->id])->sort()->values()->all());
});

test('saving notes keeps the answer open', function () {
    $checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $item = ChecklistItem::factory()->create(['checklist_id' => $checklist->id]);
    $run = $this->service->runFor($checklist);

    $this->service->saveNotes($run, $item, '  Waiting on keys  ');

    expect($run->answers()->sole())->notes->toBe('Waiting on keys')->value->toBeNull()
        ->and($run->fresh()->completed_at)->toBeNull();
});

describe('templates', function () {
    test('each operating class starts with the checklist its bonus allows', function (string $classCode, array $expected) {
        $config = EventConfiguration::factory()->create();
        $class = OperatingClass::where('event_type_id', $config->event->event_type_id)->where('code', $classCode)->first()
            ?? OperatingClass::create(['event_type_id' => $config->event->event_type_id, 'code' => $classCode, 'name' => "Class {$classCode}"]);
        $config->update(['operating_class_id' => $class->id]);

        expect(ChecklistTemplates::defaultKeysFor($config->fresh()))->toBe($expected);
    })->with([
        'A' => ['A', ['arrl_safety_officer']],
        'B' => ['B', ['arrl_site_responsibilities']],
        'F' => ['F', ['arrl_site_responsibilities']],
        'H' => ['H', []],
    ]);

    test('the ARRL lists keep every item, locked, and are limited to their safety role', function () {
        $config = EventConfiguration::factory()->create();
        $config->update(['operating_class_id' => OperatingClass::where('event_type_id', $config->event->event_type_id)->where('code', 'B')->value('id')]);

        $checklist = ChecklistTemplates::create($config->fresh(), 'arrl_site_responsibilities');

        expect($checklist->items()->count())->toBe(13)
            ->and($checklist->items->every->is_default)->toBeTrue()
            ->and($checklist->shiftRoles->pluck('name')->all())->toBe(['Site Responsibilities'])
            ->and(ChecklistTemplates::create($config, 'arrl_safety_officer')->items()->count())->toBe(15);
    });

    test('all seven templates build, and only the ARRL lists earn a bonus', function () {
        $config = EventConfiguration::factory()->create();

        $checklists = collect(array_keys(ChecklistTemplates::TEMPLATES))->map(fn ($key) => ChecklistTemplates::create($config, $key));

        expect($checklists)->toHaveCount(7)
            ->and($checklists->filter->earnsBonus()->pluck('template_key')->all())->toBe(['arrl_safety_officer', 'arrl_site_responsibilities'])
            ->and($checklists->every(fn ($checklist) => $checklist->items()->exists()))->toBeTrue();
    });

    test('a copy onto an unscored event drops the bonus', function () {
        $source = ChecklistTemplates::create(EventConfiguration::factory()->create(), 'arrl_safety_officer');

        $copy = app(ChecklistService::class)->copyTo($source, $this->eventConfig);

        expect($copy->bonus_code)->toBeNull()
            ->and($copy->items()->count())->toBe(15);
    });
});
