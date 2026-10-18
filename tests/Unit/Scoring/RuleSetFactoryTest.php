<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventType;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\WinterFieldDay2026;
use App\Scoring\Rules\WinterFieldDay2027;
use App\Scoring\RuleSetFactory;
use Illuminate\Support\Facades\DB;

uses()->group('unit', 'scoring');

test('resolves FieldDay2025 for an FD event pinned to 2025', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);

    $rules = app(RuleSetFactory::class)->forEvent($event);

    expect($rules)->toBeInstanceOf(FieldDay2025::class);
});

test('falls back to event->year when rules_version is null', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'year' => 2025,
    ]);
    DB::table('events')->where('id', $event->id)->update(['rules_version' => null]);

    $rules = app(RuleSetFactory::class)->forEvent($event->fresh());

    expect($rules)->toBeInstanceOf(FieldDay2025::class);
});

test('falls back to latest registered version when rules_version is ahead of registry', function () {
    // Only FieldDay2025 is registered at this point. A 2027 event should
    // resolve to the newest known version (2025) rather than throw, so
    // ongoing demo/testing work with future-year events keeps scoring.
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2027',
    ]);

    $rules = app(RuleSetFactory::class)->forEvent($event);

    expect($rules)->toBeInstanceOf(FieldDay2025::class);
});

test('falls back to latest registered version when rules_version is older than registry', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2010',
    ]);

    $rules = app(RuleSetFactory::class)->forEvent($event);

    // With only 2025 registered, 2010 falls back to 2025.
    expect($rules)->toBeInstanceOf(FieldDay2025::class);
});

test('throws UnknownRuleSet when event type has no registered rulesets at all', function () {
    $unknown = EventType::create(['code' => 'XXX', 'name' => 'Unknown']);
    $event = Event::factory()->create([
        'event_type_id' => $unknown->id,
        'rules_version' => '2025',
    ]);

    expect(fn () => app(RuleSetFactory::class)->forEvent($event))
        ->toThrow(UnknownRuleSet::class);
});

test('FD-TEST is not registered in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $factory = new RuleSetFactory;

    expect($factory->versionsFor('FD'))->not->toContain('TEST');
});

test('FD-TEST is registered in local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $factory = new RuleSetFactory;

    expect($factory->versionsFor('FD'))->toContain('TEST');
});

test('forEvent returns the same RuleSet instance for the same event (memoized)', function () {
    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
    ]);

    $factory = app(RuleSetFactory::class);
    $a = $factory->forEvent($event);
    $b = $factory->forEvent($event);

    expect($a)->toBe($b);
});

test('RuleSetFactory is bound as a singleton', function () {
    expect(app(RuleSetFactory::class))
        ->toBe(app(RuleSetFactory::class));
});

test('resolves WinterFieldDay2026 for a WFD event', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2026',
    ]);

    expect(app(RuleSetFactory::class)->forEvent($event))
        ->toBeInstanceOf(WinterFieldDay2026::class);
});

test('resolves WinterFieldDay2027 for a WFD event pinned to 2027', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2027',
    ]);

    expect(app(RuleSetFactory::class)->forEvent($event))
        ->toBeInstanceOf(WinterFieldDay2027::class);
});

test('a WFD event pinned to an unregistered version falls back to the newest WFD ruleset', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $event = Event::factory()->create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2025',
    ]);

    expect(app(RuleSetFactory::class)->forEvent($event)->version())->toBe('2027');
});

test('WFD reports its registered versions, keeping FD and WFD separate', function () {
    $factory = app(RuleSetFactory::class);

    expect($factory->versionsFor('WFD'))->toBe(['2026', '2027'])
        ->and($factory->versionsFor('FD'))->not->toContain('WFD');
});
