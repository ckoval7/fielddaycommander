<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\RuleSetFactory;

uses()->group('feature', 'scoring');

test('pointsForContact from factory returns the override for the event year', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    $event2025 = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);
    $config2025 = EventConfiguration::factory()->create(['event_id' => $event2025->id]);
    $station2025 = Station::factory()->create(['event_configuration_id' => $config2025->id, 'is_gota' => false]);

    $event2026 = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
    ]);
    $config2026 = EventConfiguration::factory()->create(['event_id' => $event2026->id]);
    $station2026 = Station::factory()->create(['event_configuration_id' => $config2026->id, 'is_gota' => false]);

    ModeRulePoint::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 4,
    ]);

    $factory = app(RuleSetFactory::class);

    expect($factory->forEvent($event2025)->pointsForContact($mode, $station2025))->toBe(2)
        ->and($factory->forEvent($event2026)->pointsForContact($mode, $station2026))->toBe(4);
});
