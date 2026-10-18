<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Components\DevRoleSwitcher;
use App\Livewire\Components\NotificationBell;
use App\Livewire\Logging\StationSelect;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\BroadcastChannels;
use App\Support\StationMode;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $permissions = ['log-contacts', 'view-stations', 'manage-users', 'manage-stations'];
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web'])->givePermissionTo($permissions);
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo(['log-contacts', 'view-stations']);

    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $this->device = StationDevice::factory()->create(['station_id' => $this->station->id]);

    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
    $this->admin->assignRole('System Administrator');

    $this->attacker = User::factory()->create(['email' => 'attacker@example.com']);
    $this->attacker->assignRole('Operator');
});

/**
 * Pull the first Livewire snapshot for a component out of rendered HTML.
 */
function snapshotFrom(string $html, string $componentName): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $componentName) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot found for {$componentName}.");
}

test('a station-mode session cannot replay a snapshot from a page it cannot open', function () {
    $snapshot = snapshotFrom(
        $this->actingAs($this->attacker)->get(route('profile'))->assertOk()->getContent(),
        'profile.user-profile',
    );

    auth()->logout();

    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['email' => 'evil@example.com'],
                'calls' => [['path' => '', 'method' => 'saveProfile', 'params' => []]],
            ]],
        ])
        ->assertForbidden();

    expect($this->admin->fresh()->email)->toBe('admin@example.com');
});

test('allowed pages still accept Livewire updates in station mode', function () {
    $html = $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('logging.station-select'))
        ->assertOk()
        ->getContent();

    $this->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => snapshotFrom($html, 'logging.station-select'),
                'updates' => [],
                'calls' => [['path' => '', 'method' => 'cancelSetup', 'params' => []]],
            ]],
        ])
        ->assertOk();
});

test('owner-based policy writes are denied in station mode', function () {
    $image = Image::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'uploaded_by_user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);
    expect($this->admin->can('delete', $image))->toBeTrue();

    session([StationMode::SESSION_KEY => $this->device->id]);
    expect($this->admin->can('delete', $image))->toBeFalse()
        ->and($this->admin->can('view', $image))->toBeTrue()
        ->and($this->admin->can('log-contacts'))->toBeTrue();
});

test('a session on another station cannot be resumed from a station-mode browser', function () {
    $otherStation = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $otherSession = OperatingSession::factory()->active()->create([
        'station_id' => $otherStation->id,
        'operator_user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->withSession([StationMode::SESSION_KEY => $this->device->id]);

    Livewire::test(StationSelect::class)
        ->assertNoRedirect()
        ->assertSet('selectedStationId', $this->station->id);

    $this->get(route('logging.session', $otherSession))->assertForbidden();

    $this->postJson(route('logging.contacts.store'), [
        'uuid' => (string) Str::uuid(),
        'operating_session_id' => $otherSession->id,
        'callsign' => 'W1AW',
        'band_id' => $otherSession->band_id,
        'mode_id' => $otherSession->mode_id,
        'section_id' => Section::first()->id,
        'exchange_class' => '1A',
        'power_watts' => 100,
        'qso_time' => now()->toIso8601String(),
    ])->assertForbidden();
});

test('starting at the bound station ends the operator\'s session elsewhere', function () {
    $otherStation = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $otherSession = OperatingSession::factory()->active()->create([
        'station_id' => $otherStation->id,
        'operator_user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->withSession([StationMode::SESSION_KEY => $this->device->id]);

    Livewire::test(StationSelect::class)
        ->set('selectedBandId', $otherSession->band_id)
        ->set('selectedModeId', $otherSession->mode_id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors();

    expect($otherSession->fresh()->end_time)->not->toBeNull()
        ->and(OperatingSession::active()->forUser($this->admin->id)->sole()->station_id)->toBe($this->station->id);
});

test('station mode hides the account notifications and dev role switcher', function () {
    config(['developer.enabled' => true]);

    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->get(route('logbook.index'))
        ->assertOk()
        ->assertDontSeeLivewire(NotificationBell::class)
        ->assertDontSeeLivewire(DevRoleSwitcher::class);

    session()->forget(StationMode::SESSION_KEY);

    $this->get(route('logbook.index'))
        ->assertSeeLivewire(NotificationBell::class);
});

test('broadcast auth is reachable in station mode but the account channel is refused', function () {
    $this->actingAs($this->admin)
        ->withSession([StationMode::SESSION_KEY => $this->device->id]);

    $response = $this->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-'.BroadcastChannels::event($this->eventConfig->event_id),
    ]);

    expect($response->getContent())->not->toContain('not available in station mode');

    $userChannel = Broadcast::driver()->getChannels()->get(BroadcastChannels::user('{id}'));

    expect($userChannel($this->admin, $this->admin->id))->toBeFalse();

    session()->forget(StationMode::SESSION_KEY);

    expect($userChannel($this->admin, $this->admin->id))->toBeTrue();
});
