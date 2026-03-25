<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Services\ActiveEventService;
use App\Services\EventContextService;

beforeEach(function () {
    $this->service = app(EventContextService::class);
    session()->flush();
});

test('getContextEvent returns active event when no session override', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->getContextEvent();

    expect($result)->toBeInstanceOf(Event::class)
        ->and($result->id)->toBe($event->id);
});

test('getContextEvent returns nearest upcoming event when no active or grace period event', function () {
    $farEvent = Event::factory()->create([
        'start_time' => now()->addDays(60),
        'end_time' => now()->addDays(61),
    ]);

    $nearEvent = Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    $result = $this->service->getContextEvent();

    expect($result)->toBeInstanceOf(Event::class)
        ->and($result->id)->toBe($nearEvent->id);
});

test('getContextEvent returns null when no events exist', function () {
    $result = $this->service->getContextEvent();

    expect($result)->toBeNull();
});

test('active event takes priority over upcoming event', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $upcomingEvent = Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    $result = $this->service->getContextEvent();

    expect($result->id)->toBe($activeEvent->id);
});

test('grace period event takes priority over upcoming event', function () {
    $graceEvent = Event::factory()->create([
        'start_time' => now()->subDays(10),
        'end_time' => now()->subDays(9),
    ]);
    EventConfiguration::factory()->create(['event_id' => $graceEvent->id]);

    $upcomingEvent = Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    $result = $this->service->getContextEvent();

    expect($result->id)->toBe($graceEvent->id);
});

test('getContextEvent returns session-selected event when set', function () {
    // Create an active event
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    // Create a past event
    $pastEvent = Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);

    // Set session to view past event
    $this->service->setViewingEvent($pastEvent->id);

    $result = $this->service->getContextEvent();

    expect($result)->toBeInstanceOf(Event::class)
        ->and($result->id)->toBe($pastEvent->id);
});

test('getActiveEvent always returns date-based active regardless of session', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $pastEvent = Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    // Set session to view past event
    $this->service->setViewingEvent($pastEvent->id);

    $result = $this->service->getActiveEvent();

    expect($result)->toBeInstanceOf(Event::class)
        ->and($result->id)->toBe($activeEvent->id);
});

test('isViewingPastEvent returns false when no session override', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->isViewingPastEvent();

    expect($result)->toBeFalse();
});

test('isViewingPastEvent returns true when viewing non-active event', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $pastEvent = Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    $this->service->setViewingEvent($pastEvent->id);

    $result = $this->service->isViewingPastEvent();

    expect($result)->toBeTrue();
});

test('clearViewingEvent removes session override', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $pastEvent = Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);

    // Set session to view past event
    $this->service->setViewingEvent($pastEvent->id);
    expect($this->service->getContextEvent()->id)->toBe($pastEvent->id);

    // Clear the override
    $this->service->clearViewingEvent();

    // Should fall back to active event
    $result = $this->service->getContextEvent();
    expect($result->id)->toBe($activeEvent->id);
});

test('getGracePeriodStatus returns active for in-progress event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $result = $this->service->getGracePeriodStatus($event);

    expect($result)->toBe('active');
});

test('getGracePeriodStatus returns grace for recently ended event within grace period', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subDays(10),
        'end_time' => now()->subDays(9),
    ]);

    $result = $this->service->getGracePeriodStatus($event);

    expect($result)->toBe('grace');
});

test('getGracePeriodStatus returns archived for event past grace period', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    $result = $this->service->getGracePeriodStatus($event);

    expect($result)->toBe('archived');
});

test('getGracePeriodStatus returns upcoming for future event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    $result = $this->service->getGracePeriodStatus($event);

    expect($result)->toBe('upcoming');
});

test('getGracePeriodStatus defaults to 30 days when setting not configured', function () {
    // Event ended 29 days ago — should be in grace period with 30-day default
    $graceEvent = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);

    // Event ended 31 days ago — should be archived with 30-day default
    $archivedEvent = Event::factory()->create([
        'start_time' => now()->subDays(32),
        'end_time' => now()->subDays(31),
    ]);

    expect($this->service->getGracePeriodStatus($graceEvent))->toBe('grace')
        ->and($this->service->getGracePeriodStatus($archivedEvent))->toBe('archived');
});

test('setViewingEvent with invalid event ID does not crash', function () {
    // Should not throw an exception
    $this->service->setViewingEvent(99999);

    // getContextEvent should fall back to active event (or null)
    $result = $this->service->getContextEvent();

    expect($result)->toBeNull();
});

test('getContextEventId returns event ID or no-event', function () {
    // No active event
    expect($this->service->getContextEventId())->toBe('no-event');

    // With active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $this->service->clearCache();

    expect($this->service->getContextEventId())->toBe($event->id);
});

test('service is registered as scoped binding', function () {
    $instance1 = app(EventContextService::class);
    $instance2 = app(EventContextService::class);

    expect($instance1)->toBe($instance2);
});

it('is registered as a scoped binding, not singleton', function () {
    $service1 = app(\App\Services\EventContextService::class);
    app()->forgetScopedInstances();
    $service2 = app(\App\Services\EventContextService::class);
    expect($service1)->not->toBe($service2);
});

test('existing ActiveEventService alias resolves to EventContextService', function () {
    $contextService = app(EventContextService::class);
    $aliasedService = app(ActiveEventService::class);

    expect($aliasedService)->toBeInstanceOf(EventContextService::class)
        ->and($aliasedService)->toBe($contextService);
});

test('getEventConfiguration returns configuration from context event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->getEventConfiguration();

    expect($result)->toBeInstanceOf(EventConfiguration::class)
        ->and($result->id)->toBe($config->id);
});

test('hasContextEvent returns true when active event exists', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    expect($this->service->hasContextEvent())->toBeTrue();
});

test('hasContextEvent returns false when no event exists', function () {
    expect($this->service->hasContextEvent())->toBeFalse();
});

test('getActiveEventId returns no-event when no active event', function () {
    expect($this->service->getActiveEventId())->toBe('no-event');
});

test('hasActiveEvent returns true when event is active', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    expect($this->service->hasActiveEvent())->toBeTrue();
});

test('hasActiveEvent returns false when no event is active', function () {
    expect($this->service->hasActiveEvent())->toBeFalse();
});

test('getGracePeriodStatus respects custom grace period setting', function () {
    Setting::set('post_event_grace_period_days', 7);

    // Event ended 5 days ago — within 7-day grace
    $graceEvent = Event::factory()->create([
        'start_time' => now()->subDays(6),
        'end_time' => now()->subDays(5),
    ]);

    // Event ended 8 days ago — past 7-day grace
    $archivedEvent = Event::factory()->create([
        'start_time' => now()->subDays(9),
        'end_time' => now()->subDays(8),
    ]);

    expect($this->service->getGracePeriodStatus($graceEvent))->toBe('grace')
        ->and($this->service->getGracePeriodStatus($archivedEvent))->toBe('archived');
});

test('getGracePeriodStatus uses context event when no event passed', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $result = $this->service->getGracePeriodStatus();

    expect($result)->toBe('active');
});
