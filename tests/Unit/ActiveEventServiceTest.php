<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Services\ActiveEventService;

beforeEach(function () {
    $this->service = app(ActiveEventService::class);
});

test('getActiveEvent returns null when no event is active', function () {
    $result = $this->service->getActiveEvent();

    expect($result)->toBeNull();
});

test('getActiveEvent returns active event with eager-loaded configuration', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    $result = $this->service->getActiveEvent();

    expect($result)->toBeInstanceOf(Event::class)
        ->and($result->id)->toBe($event->id)
        ->and($result->relationLoaded('eventConfiguration'))->toBeTrue()
        ->and($result->eventConfiguration->id)->toBe($config->id);
});

test('getActiveEvent caches result across multiple calls', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Track queries
    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    // First call should query
    $result1 = $this->service->getActiveEvent();
    $firstCallQueries = $queryCount;

    // Second call should not query (uses cache)
    $result2 = $this->service->getActiveEvent();
    $secondCallQueries = $queryCount;

    expect($result1)->toBe($result2)
        ->and($firstCallQueries)->toBeGreaterThan(0)
        ->and($secondCallQueries)->toBe($firstCallQueries);
});

test('getEventConfiguration returns null when no event is active', function () {
    $result = $this->service->getEventConfiguration();

    expect($result)->toBeNull();
});

test('getEventConfiguration returns configuration for active event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    $result = $this->service->getEventConfiguration();

    expect($result)->toBeInstanceOf(EventConfiguration::class)
        ->and($result->id)->toBe($config->id);
});

test('getActiveEventId returns no-event string when no event is active', function () {
    $result = $this->service->getActiveEventId();

    expect($result)->toBe('no-event');
});

test('getActiveEventId returns integer event ID when event is active', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->getActiveEventId();

    expect($result)->toBe($event->id);
});

test('hasActiveEvent returns false when no event is active', function () {
    $result = $this->service->hasActiveEvent();

    expect($result)->toBeFalse();
});

test('hasActiveEvent returns true when event is active', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->hasActiveEvent();

    expect($result)->toBeTrue();
});

test('clearCache resets cached event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Load into cache
    $result1 = $this->service->getActiveEvent();
    expect($result1)->not->toBeNull();

    // Clear cache
    $this->service->clearCache();

    // Should query again after clearing
    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $result2 = $this->service->getActiveEvent();

    expect($result2)->not->toBeNull()
        ->and($queryCount)->toBeGreaterThan(0);
});

test('service is registered as singleton in container', function () {
    $instance1 = app(ActiveEventService::class);
    $instance2 = app(ActiveEventService::class);

    expect($instance1)->toBe($instance2);
});

test('caching works across different service methods', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Track queries
    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    // First method call loads the cache
    $event1 = $this->service->getActiveEvent();
    $queriesAfterFirst = $queryCount;

    // Subsequent method calls should not query
    $config = $this->service->getEventConfiguration();
    $eventId = $this->service->getActiveEventId();
    $hasEvent = $this->service->hasActiveEvent();

    expect($event1)->not->toBeNull()
        ->and($config)->not->toBeNull()
        ->and($eventId)->toBe($event->id)
        ->and($hasEvent)->toBeTrue()
        ->and($queryCount)->toBe($queriesAfterFirst); // No additional queries
});
