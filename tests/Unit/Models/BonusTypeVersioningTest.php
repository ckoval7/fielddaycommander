<?php

use App\Models\BonusType;
use App\Models\EventType;
use Illuminate\Database\QueryException;

uses()->group('unit', 'models', 'scoring');

test('bonus types are scoped by rules_version', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'test_bonus',
        'rules_version' => '2025',
        'base_points' => 100,
    ]);

    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'test_bonus',
        'rules_version' => '2026',
        'base_points' => 150,
    ]);

    $points2025 = BonusType::where('code', 'test_bonus')
        ->where('rules_version', '2025')->first()->base_points;
    $points2026 = BonusType::where('code', 'test_bonus')
        ->where('rules_version', '2026')->first()->base_points;

    expect($points2025)->toBe(100)
        ->and($points2026)->toBe(150);
});

test('same code may appear once per rules_version', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'dup_code',
        'rules_version' => '2025',
    ]);

    expect(fn () => BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'dup_code',
        'rules_version' => '2025',
    ]))->toThrow(QueryException::class);
});
