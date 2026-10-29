<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventType;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders');

/**
 * @return object{up: callable, down: callable}
 */
function fieldDaySetupMigration(): object
{
    return require database_path('migrations/2026_10_28_000003_open_field_day_setup_on_friday.php');
}

beforeEach(function () {
    $this->travelTo('2026-10-28 12:00:00');

    $this->fd = EventType::create([
        'code' => 'FD', 'name' => 'Field Day', 'description' => 'ARRL Field Day',
        'is_active' => true, 'setup_offset_hours' => 24,
    ]);
    $this->wfd = EventType::create([
        'code' => 'WFD', 'name' => 'Winter Field Day', 'description' => 'Winter Field Day',
        'is_active' => true, 'setup_offset_hours' => 24,
    ]);
});

test('the seeder opens Field Day setup 42 hours before the start and leaves WFD at 24', function () {
    EventType::query()->delete();

    $this->seed(EventTypeSeeder::class);

    expect(EventType::where('code', 'FD')->value('setup_offset_hours'))->toBe(42)
        ->and(EventType::where('code', 'WFD')->value('setup_offset_hours'))->toBe(24);
});

test('migration opens setup at 0000Z Friday on upcoming Field Day events only', function () {
    $upcoming = Event::factory()->create([
        'event_type_id' => $this->fd->id,
        'start_time' => '2027-06-26 18:00:00',
        'end_time' => '2027-06-27 20:59:00',
        'setup_allowed_from' => '2027-06-25 18:00:00',
    ]);
    $past = Event::factory()->create([
        'event_type_id' => $this->fd->id,
        'start_time' => '2026-06-27 18:00:00',
        'end_time' => '2026-06-28 20:59:00',
        'setup_allowed_from' => '2026-06-26 18:00:00',
    ]);
    $winter = Event::factory()->create([
        'event_type_id' => $this->wfd->id,
        'start_time' => '2027-01-23 16:00:00',
        'end_time' => '2027-01-24 21:59:00',
        'setup_allowed_from' => '2027-01-22 16:00:00',
    ]);

    fieldDaySetupMigration()->up();

    expect($this->fd->fresh()->setup_offset_hours)->toBe(42)
        ->and($this->wfd->fresh()->setup_offset_hours)->toBe(24)
        ->and($upcoming->fresh()->setup_allowed_from->toDateTimeString())->toBe('2027-06-25 00:00:00')
        ->and($past->fresh()->setup_allowed_from->toDateTimeString())->toBe('2026-06-26 18:00:00')
        ->and($winter->fresh()->setup_allowed_from->toDateTimeString())->toBe('2027-01-22 16:00:00');

    fieldDaySetupMigration()->down();

    expect($this->fd->fresh()->setup_offset_hours)->toBe(24)
        ->and($upcoming->fresh()->setup_allowed_from->toDateTimeString())->toBe('2027-06-25 18:00:00');
});
