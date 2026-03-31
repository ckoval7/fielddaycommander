<?php

use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

test('toLocalTime returns null for null input', function () {
    expect(toLocalTime(null))->toBeNull();
});

test('toLocalTime converts UTC to system timezone for unauthenticated user', function () {
    Setting::set('timezone', 'America/New_York');

    $utcTime = Carbon::parse('2025-06-28 18:00:00', 'UTC');
    $local = toLocalTime($utcTime);

    expect($local->timezone->getName())->toBe('America/New_York');
    expect($local->format('H:i'))->toBe('14:00');
});

test('toLocalTime uses user preferred timezone when authenticated', function () {
    Setting::set('timezone', 'America/New_York');

    $user = User::factory()->create([
        'preferred_timezone' => 'America/Los_Angeles',
    ]);
    $this->actingAs($user);

    $utcTime = Carbon::parse('2025-06-28 18:00:00', 'UTC');
    $local = toLocalTime($utcTime);

    expect($local->timezone->getName())->toBe('America/Los_Angeles');
    expect($local->format('H:i'))->toBe('11:00');
});

test('toLocalTime falls back to app timezone when no system setting exists', function () {
    $utcTime = Carbon::parse('2025-06-28 18:00:00', 'UTC');
    $local = toLocalTime($utcTime);

    // App timezone is UTC, so time should be unchanged
    expect($local->format('H:i'))->toBe('18:00');
});

test('toLocalTime does not mutate the original carbon instance', function () {
    Setting::set('timezone', 'America/New_York');

    $utcTime = Carbon::parse('2025-06-28 18:00:00', 'UTC');
    toLocalTime($utcTime);

    expect($utcTime->timezone->getName())->toBe('UTC');
    expect($utcTime->format('H:i'))->toBe('18:00');
});

test('toLocalTime accepts string input', function () {
    Setting::set('timezone', 'America/Chicago');

    $local = toLocalTime('2025-06-28 18:00:00');

    expect($local->timezone->getName())->toBe('America/Chicago');
    expect($local->format('H:i'))->toBe('13:00');
});
