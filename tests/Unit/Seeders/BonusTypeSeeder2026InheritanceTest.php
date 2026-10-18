<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\BonusType;
use App\Models\EventType;
use Database\Seeders\BonusTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders', 'scoring');

test('seeder clones 2025 Field Day bonus rows as 2026 rows so the inherited ruleset scores', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    (new BonusTypeSeeder)->run();

    $codesFor = fn (string $version) => BonusType::query()
        ->where('event_type_id', $fd->id)
        ->where('rules_version', $version)
        ->orderBy('code')
        ->pluck('code')
        ->all();

    expect($codesFor('2026'))->not->toBeEmpty()
        ->and($codesFor('2026'))->toEqual($codesFor('2025'));
});

test('WFD 2026 is seeded from its own objectives, not inherited from 2025', function () {
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    (new BonusTypeSeeder)->run();

    $wfd2026 = BonusType::query()
        ->where('event_type_id', $wfd->id)
        ->where('rules_version', '2026')
        ->get();

    // The 2026 rulebook replaced the point-bonus model with 12 objectives, so
    // the 2025 placeholder rows must not be carried forward.
    expect($wfd2026)->toHaveCount(12)
        ->and($wfd2026->pluck('code'))->not->toContain('public_location_wfd')
        ->and($wfd2026->whereNull('objective_multiplier'))->toBeEmpty();

    // The frozen 2025 rows are still there, untouched.
    expect(BonusType::query()
        ->where('event_type_id', $wfd->id)
        ->where('rules_version', '2025')
        ->pluck('code')
        ->all())
        ->toEqual(['alternative_power', 'away_from_home', 'public_location_wfd']);
});

test('seeder copies base_points and trigger_type verbatim from 2025 to 2026', function () {
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    (new BonusTypeSeeder)->run();

    $bonus2025 = BonusType::query()
        ->where('rules_version', '2025')
        ->where('code', 'nts_message')
        ->firstOrFail();

    $bonus2026 = BonusType::query()
        ->where('rules_version', '2026')
        ->where('code', 'nts_message')
        ->firstOrFail();

    expect((int) $bonus2026->base_points)->toBe((int) $bonus2025->base_points)
        ->and($bonus2026->trigger_type)->toBe($bonus2025->trigger_type)
        ->and((int) $bonus2026->max_points)->toBe((int) $bonus2025->max_points);
});

test('seeder is idempotent across repeated runs', function () {
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    (new BonusTypeSeeder)->run();
    $firstCount = BonusType::query()->where('rules_version', '2026')->count();

    (new BonusTypeSeeder)->run();
    $secondCount = BonusType::query()->where('rules_version', '2026')->count();

    expect($secondCount)->toBe($firstCount);
});
