<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\DashboardEditor;
use App\Livewire\Dashboard\DashboardManager;
use App\Models\Contact;
use App\Models\Dashboard;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $permissions = ['log-contacts', 'edit-contacts', 'view-stations', 'manage-stations', 'manage-users', 'sign-up-shifts', 'manage-own-equipment'];
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }

    Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web'])->givePermissionTo($permissions);

    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $this->device = StationDevice::factory()->withToken('device-token')->create(['station_id' => $this->station->id]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('System Administrator');
});

test('admin in station mode is capped to logging permissions', function () {
    $this->actingAs($this->admin)->withSession([StationMode::SESSION_KEY => $this->device->id]);
    $this->get(route('logbook.index'));

    expect($this->admin->can('log-contacts'))->toBeTrue()
        ->and($this->admin->can('view-stations'))->toBeTrue()
        ->and($this->admin->can('manage-users'))->toBeFalse()
        ->and($this->admin->can('manage-stations'))->toBeFalse()
        ->and($this->admin->can('edit-contacts'))->toBeFalse()
        ->and($this->admin->can('update', Contact::factory()->make()))->toBeFalse()
        ->and($this->admin->isSystemAdmin())->toBeFalse();
});

test('admin outside station mode keeps full permissions', function () {
    $this->actingAs($this->admin);

    expect($this->admin->can('manage-users'))->toBeTrue()
        ->and($this->admin->isSystemAdmin())->toBeTrue();
});

test('station-mode session cannot reach admin or account pages', function (string $routeName, array $parameters) {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'users' => ['users.index', []],
    'profile' => ['profile', []],
    'station create' => ['stations.create', []],
    'transcribe' => ['logging.transcribe.select', []],
    'my equipment' => ['equipment.index', []],
    'my shifts' => ['schedule.my-shifts', []],
]);

test('station-mode session can reach read-only pages', function (string $routeName) {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route($routeName))
        ->assertOk();
})->with([
    'logbook' => 'logbook.index',
    'stations' => 'stations.index',
    'schedule' => 'schedule.index',
    'club equipment' => 'equipment.club',
    'logging' => 'logging.station-select',
]);

test('ghost operator can log without a role and is not sent to change password', function () {
    $ghost = User::factory()->ghost()->create();

    $this->actingAs($ghost)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('logging.station-select'))
        ->assertOk();
});

test('ghost outside station mode has no permissions', function () {
    $ghost = User::factory()->ghost()->create();

    expect($ghost->can('log-contacts'))->toBeFalse();
});

test('ending the session signs out but keeps the browser enrolled', function () {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->withCookie(StationMode::COOKIE, 'device-token')
        ->post(route('station-mode.end'))
        ->assertRedirect(route('station-mode.landing'))
        ->assertCookieMissing(StationMode::COOKIE);

    $this->assertGuest();
    expect($this->device->fresh()->isRevoked())->toBeFalse();
});

test('station-mode header offers the theme switcher next to end session', function () {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('logbook.index'))
        ->assertOk()
        ->assertSee('data-station-mode-controls', false)
        ->assertSee('Sign Out')
        ->assertSee('Switch to dark mode', false);
});

test('station-mode pages include the idle session warning', function () {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('logbook.index'))
        ->assertOk()
        ->assertSee('data-station-mode-idle-warning', false)
        ->assertSee('warnAfterMs: '.(StationMode::IDLE_WARNING_AFTER_SECONDS * 1000), false)
        ->assertSee('countdownSeconds: '.StationMode::IDLE_COUNTDOWN_SECONDS, false)
        ->assertSee(route('station-mode.end'), false);
});

test('normal sessions do not include the idle session warning', function () {
    $this->actingAs($this->admin)
        ->get(route('logbook.index'))
        ->assertOk()
        ->assertDontSee('data-station-mode-idle-warning', false);
});

test('station-mode dashboard hides the edit and manage controls', function () {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Edit Layout')
        ->assertDontSee('dashboard-manager');
});

test('normal dashboard shows the edit and manage controls', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Edit Layout')
        ->assertSee('dashboard-manager');
});

test('station-mode session cannot edit the dashboard layout', function (string $method) {
    $dashboard = Dashboard::factory()->create(['user_id' => $this->admin->id]);
    $this->actingAs($this->admin);

    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard]);
    session([StationMode::SESSION_KEY => $this->device->id]);

    $component->call($method)->assertForbidden();
})->with(['enterEditMode', 'saveLayout']);

test('station-mode session cannot manage dashboards', function () {
    $dashboard = Dashboard::factory()->create(['user_id' => $this->admin->id]);
    $this->actingAs($this->admin);

    $component = Livewire::test(DashboardManager::class, ['currentDashboardId' => $dashboard->id])
        ->set('newTitle', 'Sneaky');
    session([StationMode::SESSION_KEY => $this->device->id]);

    $component->call('createDashboard')->assertForbidden();

    expect(Dashboard::where('title', 'Sneaky')->exists())->toBeFalse();
});
