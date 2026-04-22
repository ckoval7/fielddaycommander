<?php

use App\Models\BonusType;
use App\Models\Event;
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

test('resolveFor returns the row scoped to the event event_type and rules_version', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'shared',
        'rules_version' => '2025',
        'base_points' => 10,
    ]);
    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'shared',
        'rules_version' => 'TEST',
        'base_points' => 99,
    ]);
    BonusType::factory()->create([
        'event_type_id' => $wfd->id,
        'code' => 'shared',
        'rules_version' => '2025',
        'base_points' => 77,
    ]);

    $event2025 = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);
    $eventTest = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => 'TEST',
    ]);

    expect(BonusType::resolveFor($event2025, 'shared')?->base_points)->toBe(10)
        ->and(BonusType::resolveFor($eventTest, 'shared')?->base_points)->toBe(99)
        ->and(BonusType::resolveFor($event2025, 'does_not_exist'))->toBeNull();
});

test('resolveFor follows resolved_rules_version when the requested version is unregistered', function () {
    // Event pinned to an unregistered year → RuleSetFactory falls back to 2025,
    // so resolveFor should return the 2025 row, not null.
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    BonusType::factory()->create([
        'event_type_id' => $fd->id,
        'code' => 'fallback_probe',
        'rules_version' => '2025',
        'base_points' => 5,
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2099', // not registered
    ]);

    expect(BonusType::resolveFor($event, 'fallback_probe')?->base_points)->toBe(5);
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
