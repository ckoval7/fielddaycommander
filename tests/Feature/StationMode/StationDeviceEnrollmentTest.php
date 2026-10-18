<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Stations\StationsList;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    Permission::firstOrCreate(['name' => 'view-stations']);
    Permission::firstOrCreate(['name' => 'manage-stations']);
    Permission::firstOrCreate(['name' => 'log-contacts']);

    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo(['view-stations', 'log-contacts']);
    Role::firstOrCreate(['name' => 'Station Captain', 'guard_name' => 'web'])->givePermissionTo(['view-stations', 'manage-stations', 'log-contacts']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole('Operator');

    $this->captain = User::factory()->create();
    $this->captain->assignRole('Station Captain');

    $this->event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'HF CW',
    ]);
});

test('station captain can enroll this browser for a station', function () {
    $this->actingAs($this->captain);

    Livewire::test(StationsList::class)
        ->call('enrollThisBrowser', $this->station->id)
        ->assertRedirect(route('station-mode.landing'));

    expect(Cookie::hasQueued(StationMode::COOKIE))->toBeTrue();

    $device = StationDevice::sole();

    expect($device->station_id)->toBe($this->station->id)
        ->and($device->enrolled_by_user_id)->toBe($this->captain->id)
        ->and($device->revoked_at)->toBeNull()
        ->and(AuditLog::where('action', 'station.device_enrolled')->exists())->toBeTrue();

    $this->assertGuest();
});

test('operator cannot enroll a browser', function () {
    $this->actingAs($this->operator);

    Livewire::test(StationsList::class)
        ->call('enrollThisBrowser', $this->station->id)
        ->assertForbidden();

    expect(StationDevice::count())->toBe(0);
});

test('station captain can revoke an enrolled browser', function () {
    $device = StationDevice::factory()->create(['station_id' => $this->station->id]);

    $this->actingAs($this->captain);

    Livewire::test(StationsList::class)
        ->assertSee('Station-Mode Browsers')
        ->call('revokeDevice', $device->id)
        ->assertDispatched('toast');

    expect($device->fresh()->isRevoked())->toBeTrue()
        ->and(AuditLog::where('action', 'station.device_revoked')->exists())->toBeTrue();
});

test('operator cannot revoke an enrolled browser', function () {
    $device = StationDevice::factory()->create(['station_id' => $this->station->id]);

    $this->actingAs($this->operator);

    Livewire::test(StationsList::class)
        ->assertDontSee('Station-Mode Browsers')
        ->call('revokeDevice', $device->id)
        ->assertForbidden();

    expect($device->fresh()->isRevoked())->toBeFalse();
});

test('a revoked device signs out its station-mode operator', function () {
    $device = StationDevice::factory()->withToken('device-token')->revoked()->create(['station_id' => $this->station->id]);

    $this->actingAs($this->operator)
        ->withSession([StationMode::SESSION_KEY => $device->id])
        ->get(route('logbook.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
