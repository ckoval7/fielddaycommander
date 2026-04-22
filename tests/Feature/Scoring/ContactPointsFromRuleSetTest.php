<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\RuleSetFactory;

uses()->group('feature', 'scoring');

test('pointsForContact from factory uses the override registered for the ruleset version', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'is_gota' => false]);

    ModeRulePoint::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
        'mode_id' => $mode->id,
        'points' => 4,
    ]);

    $factory = app(RuleSetFactory::class);

    expect($factory->forEvent($event)->pointsForContact($mode, $station))->toBe(4);
});

test('events pinned to an unshipped rules_version fall back to the newest registered ruleset', function () {
    // An event pinned to a future year should score with the newest registered
    // ruleset, and overrides registered for the unshipped version must NOT apply.
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2027',
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'is_gota' => false]);

    ModeRulePoint::create([
        'event_type_id' => $fd->id,
        'rules_version' => '2027',
        'mode_id' => $mode->id,
        'points' => 4,
    ]);

    $rules = app(RuleSetFactory::class)->forEvent($event);

    expect($rules->version())->toBe('2026')
        ->and($rules->pointsForContact($mode, $station))->toBe(2)
        ->and($event->resolved_rules_version)->toBe('2026')
        ->and($event->effective_rules_version)->toBe('2027');
});
