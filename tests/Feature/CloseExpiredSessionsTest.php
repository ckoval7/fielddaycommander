<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;

beforeEach(function () {
    $this->eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true]
    );

    $this->section = Section::firstOrCreate(
        ['code' => 'CO'],
        ['name' => 'Colorado', 'region' => 'W0', 'country' => 'US', 'is_active' => true]
    );
});

test('closes sessions for ended events', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(3),
        'end_time' => now()->subDay(),
    ]);

    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
    ]);

    $station = Station::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    $session = OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
        'start_time' => now()->subDays(2),
    ]);

    $this->artisan('sessions:close-expired')
        ->assertSuccessful();

    $session->refresh();
    expect($session->end_time)->not->toBeNull();
    expect($session->end_time->toDateTimeString())->toBe($event->end_time->toDateTimeString());
});

test('does not close sessions for active events', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);

    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
    ]);

    $station = Station::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    $session = OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
    ]);

    $this->artisan('sessions:close-expired')
        ->assertSuccessful();

    $session->refresh();
    expect($session->end_time)->toBeNull();
});

test('does not affect already closed sessions', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(3),
        'end_time' => now()->subDay(),
    ]);

    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'section_id' => $this->section->id,
    ]);

    $station = Station::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    $originalEndTime = now()->subDays(2);
    $session = OperatingSession::factory()->ended()->create([
        'station_id' => $station->id,
        'end_time' => $originalEndTime,
    ]);

    $this->artisan('sessions:close-expired')
        ->assertSuccessful();

    $session->refresh();
    expect($session->end_time->toDateTimeString())->toBe($originalEndTime->toDateTimeString());
});

test('outputs message when no expired sessions found', function () {
    $this->artisan('sessions:close-expired')
        ->expectsOutput('No expired sessions found.')
        ->assertSuccessful();
});
