<?php

use App\Enums\ChecklistType;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;

describe('Relationships', function () {
    test('belongs to event configuration', function () {
        $eventConfig = EventConfiguration::factory()->create();
        $item = SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
        ]);

        expect($item->eventConfiguration)
            ->toBeInstanceOf(EventConfiguration::class)
            ->id->toBe($eventConfig->id);
    });

    test('has one entry', function () {
        $item = SafetyChecklistItem::factory()->create();
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        expect($item->entry)
            ->toBeInstanceOf(SafetyChecklistEntry::class)
            ->id->toBe($entry->id);
    });
});

describe('Scopes', function () {
    test('forEvent filters by event configuration id', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        SafetyChecklistItem::factory()->count(3)->create(['event_configuration_id' => $event1->id]);
        SafetyChecklistItem::factory()->create(['event_configuration_id' => $event2->id]);

        $filtered = SafetyChecklistItem::forEvent($event1->id)->get();

        expect($filtered)->toHaveCount(3)
            ->each(fn ($item) => $item->event_configuration_id->toBe($event1->id));
    });

    test('byType filters by checklist type', function () {
        $eventConfig = EventConfiguration::factory()->create();

        SafetyChecklistItem::factory()->count(2)->create([
            'event_configuration_id' => $eventConfig->id,
            'checklist_type' => ChecklistType::SafetyOfficer,
        ]);
        SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'checklist_type' => ChecklistType::SiteResponsibilities,
        ]);

        $filtered = SafetyChecklistItem::forEvent($eventConfig->id)
            ->byType(ChecklistType::SafetyOfficer)
            ->get();

        expect($filtered)->toHaveCount(2);
    });

    test('required scope filters required items', function () {
        $eventConfig = EventConfiguration::factory()->create();

        SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'is_required' => true,
        ]);
        SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'is_required' => false,
        ]);

        expect(SafetyChecklistItem::forEvent($eventConfig->id)->required()->count())->toBe(1);
    });

    test('ordered scope sorts by sort_order', function () {
        $eventConfig = EventConfiguration::factory()->create();

        SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'sort_order' => 2,
            'label' => 'Second',
        ]);
        SafetyChecklistItem::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'sort_order' => 1,
            'label' => 'First',
        ]);

        $items = SafetyChecklistItem::forEvent($eventConfig->id)->ordered()->get();

        expect($items->first()->label)->toBe('First');
        expect($items->last()->label)->toBe('Second');
    });
});

describe('Seeding', function () {
    test('seedDefaults creates safety officer items for Class A', function () {
        $operatingClass = \App\Models\OperatingClass::factory()->create(['code' => 'A']);
        $eventConfig = EventConfiguration::factory()->create([
            'operating_class_id' => $operatingClass->id,
        ]);

        SafetyChecklistItem::seedDefaults($eventConfig);

        $items = SafetyChecklistItem::forEvent($eventConfig->id)
            ->byType(ChecklistType::SafetyOfficer)
            ->get();

        expect($items->count())->toBe(15);
        expect($items->every(fn ($item) => $item->is_required))->toBeTrue();
        expect($items->every(fn ($item) => $item->is_default))->toBeTrue();
    });

    test('seedDefaults does not duplicate on re-run', function () {
        $eventConfig = EventConfiguration::factory()->create();

        SafetyChecklistItem::seedDefaults($eventConfig);
        $countFirst = SafetyChecklistItem::forEvent($eventConfig->id)->count();

        SafetyChecklistItem::seedDefaults($eventConfig);
        $countSecond = SafetyChecklistItem::forEvent($eventConfig->id)->count();

        expect($countSecond)->toBe($countFirst);
    });

    test('seedDefaults creates entries for each item', function () {
        $eventConfig = EventConfiguration::factory()->create();

        SafetyChecklistItem::seedDefaults($eventConfig);

        $items = SafetyChecklistItem::forEvent($eventConfig->id)->with('entry')->get();

        $items->each(function ($item) {
            expect($item->entry)->not->toBeNull();
            expect($item->entry->is_completed)->toBeFalse();
        });
    });
});
