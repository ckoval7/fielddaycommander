<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
    $this->fdTypeId = EventType::where('code', 'FD')->value('id');

    // These migrations ran against the fixed-type checklist tables, which a
    // later migration moved into checklist records and dropped.
    (require database_path('migrations/2026_10_24_000001_move_safety_checklists_to_checklist_records.php'))->down();
});

function fieldDayBonusType(int $eventTypeId, string $code, string $version = '2025'): BonusType
{
    return BonusType::where('event_type_id', $eventTypeId)->where('rules_version', $version)->where('code', $code)->sole();
}

function checklistItem(EventConfiguration $config, string $type, bool $completed, bool $required = true): void
{
    $itemId = DB::table('safety_checklist_items')->insertGetId([
        'event_configuration_id' => $config->id,
        'checklist_type' => $type,
        'label' => fake()->unique()->sentence(3),
        'is_required' => $required,
        'is_default' => true,
        'sort_order' => 0,
    ]);
    DB::table('safety_checklist_entries')->insert(['safety_checklist_item_id' => $itemId, 'is_completed' => $completed]);
}

test('computed and checklist field day bonuses are derived for every rules version', function () {
    BonusType::where('event_type_id', $this->fdTypeId)
        ->whereIn('code', ['emergency_power', 'satellite_qso', 'safety_officer', 'site_responsibilities'])
        ->update(['trigger_type' => 'manual']);

    (require database_path('migrations/2026_10_17_230000_derive_computed_field_day_bonuses.php'))->up();

    foreach (['2025', '2026'] as $version) {
        foreach (['emergency_power', 'satellite_qso', 'safety_officer', 'site_responsibilities'] as $code) {
            expect(fieldDayBonusType($this->fdTypeId, $code, $version)->trigger_type)->toBe('derived');
        }
    }

    expect(fieldDayBonusType($this->fdTypeId, 'social_media')->trigger_type)->toBe('manual');
});

test('manually claimed safety bonuses without a complete checklist are removed', function () {
    $earned = EventConfiguration::factory()->create();
    checklistItem($earned, 'safety_officer', completed: true);

    $skipped = EventConfiguration::factory()->create();
    checklistItem($skipped, 'safety_officer', completed: false);

    $safetyOfficer = fieldDayBonusType($this->fdTypeId, 'safety_officer');

    foreach ([$earned, $skipped] as $config) {
        EventBonus::factory()->create([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $safetyOfficer->id,
            'calculated_points' => 100,
            'is_verified' => true,
        ]);
    }

    (require database_path('migrations/2026_10_17_230000_derive_computed_field_day_bonuses.php'))->up();

    expect(EventBonus::where('event_configuration_id', $earned->id)->exists())->toBeTrue()
        ->and(EventBonus::where('event_configuration_id', $skipped->id)->exists())->toBeFalse();
});

test('safety officer bonus and checklist are removed from non class A events', function () {
    $classA = EventConfiguration::factory()->create();
    $classF = EventConfiguration::factory()->create();
    $classF->update(['operating_class_id' => OperatingClass::where('event_type_id', $classF->event->event_type_id)->where('code', 'F')->value('id')]);

    $safetyOfficer = fieldDayBonusType($this->fdTypeId, 'safety_officer');
    $siteResponsibilities = fieldDayBonusType($this->fdTypeId, 'site_responsibilities');

    foreach ([$classA, $classF] as $config) {
        checklistItem($config, 'safety_officer', completed: true);
        EventBonus::factory()->create(['event_configuration_id' => $config->id, 'bonus_type_id' => $safetyOfficer->id, 'is_verified' => true]);
    }
    checklistItem($classF, 'site_responsibilities', completed: true);
    EventBonus::factory()->create(['event_configuration_id' => $classF->id, 'bonus_type_id' => $siteResponsibilities->id, 'is_verified' => true]);

    (require database_path('migrations/2026_10_17_230001_remove_safety_officer_from_non_class_a_events.php'))->up();

    expect(EventBonus::where('event_configuration_id', $classA->id)->pluck('bonus_type_id')->all())->toBe([$safetyOfficer->id])
        ->and(EventBonus::where('event_configuration_id', $classF->id)->pluck('bonus_type_id')->all())->toBe([$siteResponsibilities->id])
        ->and(DB::table('safety_checklist_items')->where('event_configuration_id', $classF->id)->pluck('checklist_type')->all())->toBe(['site_responsibilities'])
        ->and(DB::table('safety_checklist_items')->where('event_configuration_id', $classA->id)->count())->toBe(1);
});
