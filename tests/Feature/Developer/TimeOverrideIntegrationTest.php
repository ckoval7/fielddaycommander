<?php

use App\Models\Event;
use App\Models\Setting;
use App\Services\DeveloperClockService;
use Carbon\Carbon;

beforeEach(function () {
    // Ensure required data is seeded
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);

    // Enable developer mode for tests
    config(['developer.enabled' => true]);
    config(['app.env' => 'testing']);
});

afterEach(function () {
    // Clean up fake time settings
    Setting::set('dev.fake_time', null);
    Setting::set('dev.time_frozen', null);
    Setting::set('dev.fake_time_set_at', null);
});

describe('basic time override', function () {
    test('appNow() returns fake time when set', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        expect(appNow()->toDateTimeString())->toBe('2025-06-28 18:00:00');
    });

    test('appNow() returns real time when fake time is cleared', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        expect(appNow()->toDateTimeString())->toBe('2025-06-28 18:00:00');

        $service->clearFakeTime();

        // appNow() should return actual current time
        expect(appNow()->year)->toBe((int) date('Y'));
        expect(appNow()->month)->toBe((int) date('n'));
        expect(appNow()->day)->toBe((int) date('j'));
    });

    test('now() is NOT affected by fake time (security safe)', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        // appNow() should return fake time
        expect(appNow()->toDateTimeString())->toBe('2025-06-28 18:00:00');

        // now() should return real time (important for CSRF, sessions, etc.)
        expect(now()->year)->toBe((int) date('Y'));
    });

    test('frozen time stays fixed across multiple appNow() calls', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime, frozen: true);

        $firstCall = appNow()->toDateTimeString();
        sleep(1); // Wait a second
        $secondCall = appNow()->toDateTimeString();

        expect($firstCall)->toBe('2025-06-28 18:00:00');
        expect($secondCall)->toBe('2025-06-28 18:00:00');
        expect($firstCall)->toBe($secondCall);
    });

    test('flowing time advances from fake starting point', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime, frozen: false);

        $firstCall = appNow();
        sleep(1); // Wait a second
        $secondCall = appNow();

        // Time should have advanced
        expect($secondCall->greaterThan($firstCall))->toBeTrue();
        expect($firstCall->diffInSeconds($secondCall))->toBeGreaterThanOrEqual(1);
    });
});

describe('event integration', function () {
    test('event shows as upcoming when fake time is before event start', function () {
        // Create event
        $event = Event::factory()->create([
            'start_time' => Carbon::parse('2025-06-28 18:00:00'),
            'end_time' => Carbon::parse('2025-06-29 20:59:00'),
        ]);

        // Set fake time to before the event
        $fakeTime = Carbon::parse('2025-06-27 12:00:00');
        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        // Event status uses appNow() internally
        expect($event->fresh()->status)->toBe('upcoming');
    });

    test('event shows as in_progress when fake time is during event', function () {
        // Create event
        $event = Event::factory()->create([
            'start_time' => Carbon::parse('2025-06-28 18:00:00'),
            'end_time' => Carbon::parse('2025-06-29 20:59:00'),
        ]);

        // Set fake time to during the event
        $fakeTime = Carbon::parse('2025-06-29 12:00:00');
        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        expect($event->fresh()->status)->toBe('in_progress');
    });

    test('event shows as completed when fake time is after event', function () {
        // Create event
        $event = Event::factory()->create([
            'start_time' => Carbon::parse('2025-06-28 18:00:00'),
            'end_time' => Carbon::parse('2025-06-29 20:59:00'),
        ]);

        // Set fake time to after the event
        $fakeTime = Carbon::parse('2025-06-30 12:00:00');
        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        expect($event->fresh()->status)->toBe('completed');
    });

    test('event status changes as fake time is adjusted', function () {
        // Create event
        $event = Event::factory()->create([
            'start_time' => Carbon::parse('2025-06-28 18:00:00'),
            'end_time' => Carbon::parse('2025-06-29 20:59:00'),
        ]);

        $service = app(DeveloperClockService::class);

        // Before event
        $service->setFakeTime(Carbon::parse('2025-06-27 12:00:00'));
        expect($event->fresh()->status)->toBe('upcoming');

        // During event
        $service->setFakeTime(Carbon::parse('2025-06-29 12:00:00'));
        expect($event->fresh()->status)->toBe('in_progress');

        // After event
        $service->setFakeTime(Carbon::parse('2025-06-30 12:00:00'));
        expect($event->fresh()->status)->toBe('completed');
    });
});

describe('production safety', function () {
    test('appNow() returns real time when developer mode is disabled', function () {
        config(['developer.enabled' => false]);

        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        // appNow() should return actual current time when dev mode disabled
        expect(appNow()->year)->toBe((int) date('Y'));
    });

    test('isEnabled returns false when config disabled', function () {
        config(['developer.enabled' => false]);

        $service = app(DeveloperClockService::class);

        expect($service->isEnabled())->toBeFalse();
    });

    test('hasTimeOverride returns false when no fake time set', function () {
        config(['developer.enabled' => true]);

        $service = app(DeveloperClockService::class);

        expect($service->hasTimeOverride())->toBeFalse();
    });

    test('hasTimeOverride returns true when fake time is set', function () {
        config(['developer.enabled' => true]);

        $service = app(DeveloperClockService::class);
        $service->setFakeTime(Carbon::parse('2025-06-28 18:00:00'));

        expect($service->hasTimeOverride())->toBeTrue();
    });
});

describe('persistence', function () {
    test('fake time persists in settings table', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime, frozen: true);

        $stored = Setting::get('dev.fake_time');
        expect($stored)->not->toBeNull();
        expect(Carbon::parse($stored)->toDateTimeString())->toBe('2025-06-28 18:00:00');

        $frozen = Setting::get('dev.time_frozen');
        expect($frozen)->toBe(1); // JSON decoded to integer
    });

    test('clearing fake time removes all settings', function () {
        $fakeTime = Carbon::parse('2025-06-28 18:00:00');

        $service = app(DeveloperClockService::class);
        $service->setFakeTime($fakeTime);

        expect(Setting::get('dev.fake_time'))->not->toBeNull();
        expect(Setting::get('dev.time_frozen'))->not->toBeNull();
        expect(Setting::get('dev.fake_time_set_at'))->not->toBeNull();

        $service->clearFakeTime();

        expect(Setting::get('dev.fake_time'))->toBeNull();
        expect(Setting::get('dev.time_frozen'))->toBeNull();
        expect(Setting::get('dev.fake_time_set_at'))->toBeNull();
    });

    test('service reads from settings on each call (no stale cache)', function () {
        $service = app(DeveloperClockService::class);

        // Initially no fake time
        expect(appNow()->year)->toBe((int) date('Y'));

        // Set fake time directly in settings
        Setting::set('dev.fake_time', '2025-06-28T18:00:00+00:00');
        Setting::set('dev.time_frozen', '1');
        Setting::set('dev.fake_time_set_at', now()->toIso8601String());

        // Service should pick up the new value
        expect(appNow()->toDateTimeString())->toBe('2025-06-28 18:00:00');
    });
});
