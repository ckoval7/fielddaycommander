<?php

use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoints;
use Illuminate\Database\QueryException;

uses()->group('unit', 'models', 'scoring');

test('mode rule points row stores points for (event_type, rules_version, mode)', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    ModeRulePoints::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 3,
    ]);

    $row = ModeRulePoints::where([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
    ])->first();

    expect($row->points)->toBe(3);
});

test('unique constraint prevents duplicate override per scope', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $mode = Mode::factory()->create(['name' => 'CW']);

    ModeRulePoints::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 3,
    ]);

    expect(fn () => ModeRulePoints::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 4,
    ]))->toThrow(QueryException::class);
});
