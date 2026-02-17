<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Services\EventContextService;

test('user can switch events and see past event data', function () {
    $user = User::factory()->create();

    $activeEvent = Event::factory()->create([
        'name' => 'FD 2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $pastEvent = Event::factory()->create([
        'name' => 'FD 2024',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);

    $this->actingAs($user);
    session(['viewing_event_id' => $pastEvent->id]);

    $service = app(EventContextService::class);

    expect($service->getContextEvent()->id)->toBe($pastEvent->id)
        ->and($service->getActiveEvent()->id)->toBe($activeEvent->id)
        ->and($service->isViewingPastEvent())->toBeTrue();
});

test('session is cleared on logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    session(['viewing_event_id' => 123]);

    auth()->logout();
    session()->invalidate();

    expect(session('viewing_event_id'))->toBeNull();
});

test('grace period status transitions correctly over time', function () {
    Setting::set('post_event_grace_period_days', 7);

    $service = app(EventContextService::class);

    // Event ended 3 days ago — within 7-day grace
    $recentEvent = Event::factory()->create([
        'start_time' => now()->subDays(4),
        'end_time' => now()->subDays(3),
    ]);
    expect($service->getGracePeriodStatus($recentEvent))->toBe('grace');

    // Event ended 10 days ago — past 7-day grace
    $olderEvent = Event::factory()->create([
        'start_time' => now()->subDays(11),
        'end_time' => now()->subDays(10),
    ]);
    expect($service->getGracePeriodStatus($olderEvent))->toBe('archived');
});

test('context event defaults to active when no session override', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $service = app(EventContextService::class);

    expect($service->getContextEvent()->id)->toBe($activeEvent->id)
        ->and($service->isViewingPastEvent())->toBeFalse();
});

test('switching back to active event clears session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $service = app(EventContextService::class);

    // Set a past event
    $service->setViewingEvent(999);
    expect(session('viewing_event_id'))->toBe(999);

    // Clear it
    $service->clearViewingEvent();
    expect(session('viewing_event_id'))->toBeNull();
});
