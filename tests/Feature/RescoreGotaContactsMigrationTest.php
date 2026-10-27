<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $this->seed(EventTypeSeeder::class);

    $this->phone = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->cw = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0]);

    $event = Event::factory()->create([
        'event_type_id' => EventType::where('code', 'FD')->value('id'),
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $event->id, 'has_gota_station' => true]);

    $this->contact = fn (array $attributes) => Contact::factory()->create(array_merge([
        'event_configuration_id' => $this->config->id,
        'band_id' => $this->band->id,
        'qso_time' => now(),
        'is_duplicate' => false,
    ], $attributes));

    $this->migration = require database_path('migrations/2026_10_27_000000_rescore_gota_contacts_as_qso_points.php');
});

test('reprices GOTA contacts stored at the old flat 5 points by mode', function () {
    $phone = ($this->contact)(['callsign' => 'W1AW', 'mode_id' => $this->phone->id, 'is_gota_contact' => true, 'points' => 5]);
    $cw = ($this->contact)(['callsign' => 'K1ABC', 'mode_id' => $this->cw->id, 'is_gota_contact' => true, 'points' => 5]);
    $primary = ($this->contact)(['callsign' => 'N1XYZ', 'mode_id' => $this->cw->id, 'is_gota_contact' => false, 'points' => 2]);

    $this->migration->up();

    expect($phone->fresh()->points)->toBe(1)
        ->and($cw->fresh()->points)->toBe(2)
        ->and($primary->fresh()->points)->toBe(2)
        ->and($this->config->fresh()->qsoBasePoints())->toBe(5)
        ->and($this->config->fresh()->calculateGotaBonus())->toBe(10);
});
