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
    $this->digital = Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0]);

    $event = Event::factory()->create([
        'event_type_id' => EventType::where('code', 'FD')->value('id'),
        'rules_version' => '2026',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $this->contact = fn (array $attributes) => Contact::factory()->create(array_merge([
        'event_configuration_id' => $this->config->id,
        'band_id' => $this->band->id,
        'qso_time' => now(),
        'is_duplicate' => false,
        'is_gota_contact' => false,
    ], $attributes));

    $this->migration = require database_path('migrations/2026_10_19_003214_reresolve_contact_modes.php');
});

test('gives contacts stored with no mode a mode from their sub-mode and rescores them', function () {
    $olivia = ($this->contact)(['callsign' => 'W1AW', 'mode_id' => null, 'submode' => 'OLIVIA 8/250', 'points' => 0]);
    $blank = ($this->contact)(['callsign' => 'K1ABC', 'mode_id' => null, 'submode' => null, 'points' => 0]);

    $this->migration->up();

    expect($olivia->fresh())
        ->mode_id->toBe($this->digital->id)
        ->points->toBeGreaterThan(0)
        ->and($blank->fresh()->mode_id)->toBeNull();
});

test('moves digital voice contacts from Digital to Phone', function () {
    $freedv = ($this->contact)(['callsign' => 'W1AW', 'mode_id' => $this->digital->id, 'submode' => 'FreeDV', 'points' => 2]);
    $ft8 = ($this->contact)(['callsign' => 'K1ABC', 'mode_id' => $this->digital->id, 'submode' => 'FT8', 'points' => 2]);

    $this->migration->up();

    expect($freedv->fresh()->mode_id)->toBe($this->phone->id)
        ->and($ft8->fresh()->mode_id)->toBe($this->digital->id);
});
