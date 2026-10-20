<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventConfiguration;
use App\Models\Net;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('puts entries logged before nets existed on one net per event', function () {
    $migration = require database_path('migrations/2026_10_20_005820_add_net_id_to_net_log_entries_table.php');
    $migration->down();

    $first = EventConfiguration::factory()->create();
    $second = EventConfiguration::factory()->create();
    $entry = fn (EventConfiguration $config) => DB::table('net_log_entries')->insertGetId([
        'event_configuration_id' => $config->id,
        'logged_at' => now(),
        'from_station' => 'AID7',
        'to_station' => 'NCS',
        'message' => 'CHECK IN',
        'priority' => 'routine',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $entry($first);
    $entry($first);
    $entry($second);

    $migration->up();

    expect(Net::forEvent($first->id)->sole()->name)->toBe('Net')
        ->and(Net::forEvent($first->id)->sole()->entries()->count())->toBe(2)
        ->and(Net::forEvent($second->id)->sole()->entries()->count())->toBe(1)
        ->and(DB::table('net_log_entries')->whereNull('net_id')->count())->toBe(0);
});
