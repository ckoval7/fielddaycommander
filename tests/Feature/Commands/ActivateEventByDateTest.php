<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\Setting;

beforeEach(function () {
    // Ensure required data is seeded
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SectionSeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\OperatingClassSeeder']);

    // Clear any existing active event
    Setting::set('active_event_id', null);
    Setting::set('manual_activation', false);
});

test('activates event within date range', function () {
    // Create an event that is currently in progress
    $event = Event::factory()->create([
        'name' => 'Current Event',
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('Auto-activated event: Current Event')
        ->assertSuccessful();

    // Verify the event was activated
    expect(Setting::get('active_event_id'))->toBe($event->id);
    expect(Setting::getBoolean('manual_activation'))->toBe(false);
});

test('does not activate future event', function () {
    // Create an event that hasn't started yet
    Event::factory()->create([
        'name' => 'Future Event',
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(8),
    ]);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('No events match the current date range')
        ->assertSuccessful();

    // Verify no event was activated
    expect(Setting::get('active_event_id'))->toBeNull();
});

test('does not activate completed event', function () {
    // Create an event that has ended
    Event::factory()->create([
        'name' => 'Completed Event',
        'start_time' => now()->subDays(8),
        'end_time' => now()->subDays(7),
    ]);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('No events match the current date range')
        ->assertSuccessful();

    // Verify no event was activated
    expect(Setting::get('active_event_id'))->toBeNull();
});

test('respects manual activation flag', function () {
    // Create an event in progress
    $event = Event::factory()->create([
        'name' => 'Current Event',
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    // Set manual activation flag
    Setting::set('manual_activation', true);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('Manual activation is enabled - skipping auto-activation')
        ->assertSuccessful();

    // Verify no event was activated
    expect(Setting::get('active_event_id'))->toBeNull();
    expect(Setting::getBoolean('manual_activation'))->toBe(true);
});

test('activates earliest created when multiple match', function () {
    // Create two events that are both in progress
    $firstEvent = Event::factory()->create([
        'name' => 'First Event',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
        'created_at' => now()->subDays(2),
    ]);

    $secondEvent = Event::factory()->create([
        'name' => 'Second Event',
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
        'created_at' => now()->subDay(),
    ]);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('Auto-activated event: First Event')
        ->assertSuccessful();

    // Verify the earliest created event was activated
    expect(Setting::get('active_event_id'))->toBe($firstEvent->id);
});

test('clears active event when no events match date range', function () {
    // Create an event in the past
    Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(8),
        'end_time' => now()->subDays(7),
    ]);

    // Set an active event (simulating previously active event)
    Setting::set('active_event_id', 999);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutput('No events match the current date range')
        ->expectsOutput('Cleared active event')
        ->assertSuccessful();

    // Verify active event was cleared
    expect(Setting::get('active_event_id'))->toBeNull();
});

test('command is idempotent', function () {
    // Create an event that is currently in progress
    $event = Event::factory()->create([
        'name' => 'Current Event',
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    // Run the command twice
    $this->artisan('events:activate-by-date')->assertSuccessful();
    $firstActivation = Setting::get('active_event_id');

    $this->artisan('events:activate-by-date')->assertSuccessful();
    $secondActivation = Setting::get('active_event_id');

    // Verify it's the same event both times
    expect($firstActivation)->toBe($event->id);
    expect($secondActivation)->toBe($event->id);
});

test('auto-deactivates event when it ends', function () {
    // Create an event that has ended
    $event = Event::factory()->create([
        'name' => 'Ending Event',
        'start_time' => now()->subHours(24),
        'end_time' => now()->subHours(1),
    ]);

    // Set it as active (simulating it was active during the event)
    Setting::set('active_event_id', $event->id);
    Setting::set('manual_activation', false);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutputToContain('Auto-deactivated event (outside date range)')
        ->assertSuccessful();

    // Verify the event was deactivated
    expect(Setting::get('active_event_id'))->toBeNull();
    expect(Setting::getBoolean('manual_activation'))->toBe(false);
});

test('auto-deactivates manually activated event when it ends', function () {
    // Create an event that has ended
    $event = Event::factory()->create([
        'name' => 'Manually Activated Event',
        'start_time' => now()->subHours(24),
        'end_time' => now()->subHours(1),
    ]);

    // Set it as manually active
    Setting::set('active_event_id', $event->id);
    Setting::set('manual_activation', true);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->expectsOutputToContain('Auto-deactivated event (outside date range)')
        ->assertSuccessful();

    // Verify the event was deactivated and manual flag cleared
    expect(Setting::get('active_event_id'))->toBeNull();
    expect(Setting::getBoolean('manual_activation'))->toBe(false);
});

test('does not auto-deactivate event still in progress', function () {
    // Create an event that is in progress
    $event = Event::factory()->create([
        'name' => 'Active Event',
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(6),
    ]);

    // Set it as active
    Setting::set('active_event_id', $event->id);
    Setting::set('manual_activation', false);

    // Run the command
    $this->artisan('events:activate-by-date')
        ->assertSuccessful();

    // Verify the event is still active
    expect(Setting::get('active_event_id'))->toBe($event->id);
});
