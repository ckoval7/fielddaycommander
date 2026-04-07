<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;

beforeEach(function () {
    // Ensure required data is seeded
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SectionSeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\OperatingClassSeeder']);
});

test('event has event type relationship', function () {
    $event = Event::factory()->create();

    expect($event->eventType)->toBeInstanceOf(EventType::class);
});

test('event has event configuration relationship', function () {
    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    expect($event->eventConfiguration)->toBeInstanceOf(EventConfiguration::class);
    expect($event->eventConfiguration->id)->toBe($config->id);
});

test('event status is active when within date range', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    expect($event->fresh()->status)->toBe('active');
});

test('event status is upcoming when start time is in future', function () {
    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(8),
        'setup_allowed_from' => null,
    ]);

    expect($event->status)->toBe('upcoming');
});

test('event status is setup when in setup window', function () {
    $event = Event::factory()->create([
        'setup_allowed_from' => now()->subHours(6),
        'start_time' => now()->addHours(18),
        'end_time' => now()->addHours(45),
    ]);

    expect($event->status)->toBe('setup');
});

test('event status is upcoming when setup_allowed_from is null', function () {
    $event = Event::factory()->create([
        'setup_allowed_from' => null,
        'start_time' => now()->addHours(1),
        'end_time' => now()->addHours(28),
    ]);

    expect($event->status)->toBe('upcoming');
});

test('event status is active when within time range', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    expect($event->status)->toBe('active');
});

test('event status is completed when end time is past', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subDays(8),
        'end_time' => now()->subDays(7),
    ]);

    expect($event->status)->toBe('completed');
});

test('event scopes filter correctly', function () {
    $active = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $upcoming = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(8),
        'setup_allowed_from' => null,
    ]);
    $completed = Event::factory()->create(['start_time' => now()->subDays(8), 'end_time' => now()->subDays(7)]);

    expect(Event::active()->count())->toBe(1);
    expect(Event::upcoming()->count())->toBeGreaterThanOrEqual(1);
    expect(Event::completed()->count())->toBe(1);
});

test('inSetupWindow scope matches events in setup window', function () {
    Event::factory()->create([
        'setup_allowed_from' => now()->subHours(6),
        'start_time' => now()->addHours(18),
        'end_time' => now()->addHours(45),
    ]);

    expect(Event::inSetupWindow()->count())->toBe(1);
});

test('inSetupWindow scope excludes events without setup_allowed_from', function () {
    Event::factory()->create([
        'setup_allowed_from' => null,
        'start_time' => now()->addHours(18),
        'end_time' => now()->addHours(45),
    ]);

    expect(Event::inSetupWindow()->count())->toBe(0);
});

test('calculateSetupAllowedFrom subtracts offset hours from start time', function () {
    // 24 hours before Saturday June 28 2025 at 1800Z = Friday June 27 2025 at 1800Z
    $startTime = \Carbon\Carbon::parse('2025-06-28 18:00:00');
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 24);

    expect($setupFrom->toDateTimeString())->toBe('2025-06-27 18:00:00');
});

test('calculateSetupAllowedFrom with 48 hour offset', function () {
    $startTime = \Carbon\Carbon::parse('2025-06-28 18:00:00');
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 48);

    expect($setupFrom->toDateTimeString())->toBe('2025-06-26 18:00:00');
});

test('calculateSetupAllowedFrom with 6 hour offset for club meeting', function () {
    $startTime = \Carbon\Carbon::parse('2025-07-08 23:00:00'); // Tuesday 11pm UTC
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 6);

    expect($setupFrom->toDateTimeString())->toBe('2025-07-08 17:00:00');
});

test('setup status has warning badge color', function () {
    $event = Event::factory()->create([
        'setup_allowed_from' => now()->subHours(6),
        'start_time' => now()->addHours(18),
        'end_time' => now()->addHours(45),
    ]);

    expect($event->status)->toBe('setup');
    expect($event->status_badge_color)->toBe('warning');
});
