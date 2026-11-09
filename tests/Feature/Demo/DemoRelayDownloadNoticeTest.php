<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Admin\ExternalLoggerManagement;
use App\Livewire\Relays\RelayEnrollment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const RELAY_RELEASES_HREF = 'href="https://github.com/ckoval7/fielddaycommander-relay/releases/latest"';

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]))
        ->create(['is_active' => true]);

    $this->user = User::factory()->create()->assignRole('System Administrator');
});

test('the Relays page invites demo visitors to download the relay', function () {
    config(['demo.enabled' => true]);

    Livewire::actingAs($this->user)->test(RelayEnrollment::class)
        ->assertSeeHtml('data-test="demo-relay-download"')
        ->assertSeeHtml(RELAY_RELEASES_HREF)
        ->assertSee('Create a code below');
});

test('the External Loggers page invites demo visitors to download the relay and links to Relays', function () {
    config(['demo.enabled' => true]);

    Livewire::actingAs($this->user)->test(ExternalLoggerManagement::class)
        ->assertSeeHtml('data-test="demo-relay-download"')
        ->assertSeeHtml(RELAY_RELEASES_HREF)
        ->assertSeeHtml('href="'.route('relays.index').'"');
});

test('the relay download notice is only shown in demo mode', function () {
    Livewire::actingAs($this->user)->test(RelayEnrollment::class)
        ->assertDontSeeHtml('data-test="demo-relay-download"');

    Livewire::actingAs($this->user)->test(ExternalLoggerManagement::class)
        ->assertDontSeeHtml('data-test="demo-relay-download"');
});
