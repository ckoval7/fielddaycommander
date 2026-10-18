<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventType;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

/**
 * Covers the upgrade path for installs that already ran
 * `seed_2026_bonus_types_from_2025`, which cloned the three WFD placeholder
 * rows forward into rules_version 2026 before objectives existed.
 */
function runObjectivesMigration(): void
{
    $migration = require database_path('migrations/2026_08_21_033812_seed_winter_field_day_2026_objectives.php');
    $migration->up();
}

beforeEach(function () {
    $this->seed(EventTypeSeeder::class);
    $this->wfd = EventType::where('code', 'WFD')->firstOrFail();
});

test('placeholder rows cloned from 2025 are corrected in place, keeping their ids', function () {
    // Recreate the pre-migration state: a point-valued placeholder at 2026.
    DB::table('bonus_types')->insert([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'code' => 'alternative_power',
        'trigger_type' => 'manual',
        'name' => 'Alternative Power',
        'description' => 'Use alternative power source for entire operation',
        'base_points' => 500,
        'max_points' => 500,
        'max_occurrences' => 1,
        'requires_proof' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $originalId = DB::table('bonus_types')
        ->where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->where('code', 'alternative_power')
        ->value('id');

    runObjectivesMigration();

    $row = DB::table('bonus_types')->where('id', $originalId)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->objective_multiplier)->toBe(1)
        ->and((int) $row->base_points)->toBe(0)
        ->and($row->trigger_type)->toBe('derived')
        ->and((bool) $row->is_active)->toBeTrue();
});

test('a cloned row with no 2026 objective is deactivated rather than deleted', function () {
    DB::table('bonus_types')->insert([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'code' => 'public_location_wfd',
        'trigger_type' => 'manual',
        'name' => 'Public Location',
        'base_points' => 100,
        'max_occurrences' => 1,
        'requires_proof' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runObjectivesMigration();

    $row = DB::table('bonus_types')
        ->where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->where('code', 'public_location_wfd')
        ->first();

    expect($row)->not->toBeNull('the row must survive so any claim referencing it stays resolvable')
        ->and((bool) $row->is_active)->toBeFalse();
});

test('the migration inserts all twelve objectives and is idempotent', function () {
    runObjectivesMigration();
    runObjectivesMigration();

    $objectives = DB::table('bonus_types')
        ->where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->whereNotNull('objective_multiplier')
        ->get();

    expect($objectives)->toHaveCount(12)
        ->and($objectives->sum('objective_multiplier'))->toBe(32);
});

test('frozen WFD 2025 rows are left untouched', function () {
    DB::table('bonus_types')->insert([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2025',
        'code' => 'alternative_power',
        'trigger_type' => 'manual',
        'name' => 'Alternative Power',
        'base_points' => 500,
        'max_occurrences' => 1,
        'requires_proof' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runObjectivesMigration();

    $row = DB::table('bonus_types')
        ->where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2025')
        ->where('code', 'alternative_power')
        ->first();

    expect((int) $row->base_points)->toBe(500)
        ->and($row->objective_multiplier)->toBeNull()
        ->and((bool) $row->is_active)->toBeTrue();
});
