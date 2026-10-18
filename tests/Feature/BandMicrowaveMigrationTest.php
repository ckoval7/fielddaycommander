<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use Database\Seeders\BandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeder creates LF and microwave bands in order with Satellite last', function () {
    $this->seed(BandSeeder::class);

    $names = Band::ordered()->pluck('name')->all();

    expect($names)->toHaveCount(25)
        ->and(array_slice($names, 0, 3))->toBe(['2200m', '630m', '160m'])
        ->and($names[13])->toBe('23cm')
        ->and($names[14])->toBe('13cm')
        ->and($names[23])->toBe('1mm')
        ->and(end($names))->toBe('Satellite')
        ->and($names)->not->toContain('12m', '17m', '30m', '60m');
});

test('seeder reorders existing rows without touching other columns', function () {
    Band::create(['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'allowed_fd' => false, 'sort_order' => 1]);
    Band::create(['name' => 'Satellite', 'is_satellite' => true, 'sort_order' => 13]);

    $this->seed(BandSeeder::class);

    expect(Band::where('name', '160m')->first())
        ->sort_order->toBe(3)
        ->allowed_fd->toBeFalse()
        ->and(Band::where('name', 'Satellite')->first()->sort_order)->toBe(25);
});

test('data migration adds new bands and re-sorts an existing install', function () {
    Band::query()->delete();
    Band::create(['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'sort_order' => 1]);
    Band::create(['name' => '23cm', 'frequency_mhz' => 1240, 'sort_order' => 12]);
    Band::create(['name' => 'Satellite', 'is_satellite' => true, 'sort_order' => 13]);

    $migration = require database_path('migrations/2026_10_15_164120_seed_microwave_and_lf_bands.php');
    $migration->up();
    $migration->up();

    $names = Band::ordered()->pluck('name')->all();

    expect($names)->toHaveCount(25)
        ->and($names[0])->toBe('2200m')
        ->and($names[2])->toBe('160m')
        ->and(end($names))->toBe('Satellite')
        ->and(Band::where('name', '3cm')->first()->allowed_wfd)->toBeTrue();
});

test('grid scope hides unworked uncommon bands but keeps common bands', function () {
    $this->seed(BandSeeder::class);
    $config = EventConfiguration::factory()->create();
    $worked = Band::where('name', '3cm')->first();
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $worked->id,
        'is_duplicate' => false,
    ]);

    $names = Band::allowedForFieldDay()->ordered()->withUncommonBandsOnlyWhenWorked($config->id)->pluck('name')->all();

    expect($names)->toBe(['160m', '80m', '40m', '20m', '15m', '10m', '6m', '2m', '70cm', '3cm']);
});
