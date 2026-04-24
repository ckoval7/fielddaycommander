<?php

use App\Models\BonusType;
use App\Models\EventType;
use Database\Seeders\BonusTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders', 'scoring');

test('seeder clones 2025 bonus rows as 2026 rows so the inherited ruleset scores', function () {
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    (new BonusTypeSeeder)->run();

    $codes2025 = BonusType::query()
        ->where('rules_version', '2025')
        ->orderBy('event_type_id')->orderBy('code')
        ->get(['event_type_id', 'code'])
        ->map(fn ($row) => $row->event_type_id.'|'.$row->code)
        ->all();

    $codes2026 = BonusType::query()
        ->where('rules_version', '2026')
        ->orderBy('event_type_id')->orderBy('code')
        ->get(['event_type_id', 'code'])
        ->map(fn ($row) => $row->event_type_id.'|'.$row->code)
        ->all();

    expect($codes2026)->not->toBeEmpty()
        ->and($codes2026)->toEqual($codes2025);
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
