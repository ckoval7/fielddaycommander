<?php

use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Scoring\Exceptions\RulesVersionLocked;
use Illuminate\Support\Facades\DB;

uses()->group('unit', 'models', 'scoring');

test('event has a rules_version string column', function () {
    $event = Event::factory()->create(['year' => 2025]);

    expect($event->rules_version)->toBe('2025');
});

test('rules_version can be explicitly set to a different year', function () {
    $event = Event::factory()->create([
        'year' => 2025,
        'rules_version' => '2024',
    ]);

    expect($event->fresh()->rules_version)->toBe('2024');
});

test('existing events without rules_version fall back to year', function () {
    // Simulate pre-migration row by null-setting via raw update
    $event = Event::factory()->create(['year' => 2023]);
    DB::table('events')->where('id', $event->id)->update(['rules_version' => null]);

    expect($event->fresh()->effective_rules_version)->toBe('2023');
});

test('rules_version defaults from year on create when not supplied (observer, not factory)', function () {
    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    $event = new Event;
    $event->name = 'Test Observer Default';
    $event->event_type_id = $eventType->id;
    $event->year = 2025;
    $event->start_time = now()->addDays(30)->setTime(18, 0, 0);
    $event->end_time = now()->addDays(31)->setTime(20, 59, 0);
    $event->setup_allowed_from = now()->addDays(29)->setTime(18, 0, 0);
    $event->max_setup_hours = 24;
    $event->save();

    expect($event->fresh()->rules_version)->toBe('2025');
});

test('rules_version cannot be changed after event is locked', function () {
    $event = Event::factory()->create([
        'year' => 2025,
        'rules_version' => '2025',
        'start_time' => now()->subDay(),
        'end_time' => now()->addDay(),
    ]);

    $event->rules_version = '2026';

    expect(fn () => $event->save())
        ->toThrow(RulesVersionLocked::class);
});

test('rules_version is editable before event start even when contacts exist', function () {
    $event = Event::factory()->create([
        'year' => 2025,
        'rules_version' => '2025',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(31),
    ]);

    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Contact::factory()->create(['event_configuration_id' => $config->id]);

    $event->rules_version = '2026';
    $event->save();

    expect($event->fresh()->rules_version)->toBe('2026');
});
