<?php

use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('system user cannot start an operating session', function () {
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');

    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $systemUser->assignRole($role);

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $station = Station::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);
    $band = Band::first() ?? Band::factory()->create();
    $mode = Mode::first() ?? Mode::factory()->create();

    $this->actingAs($systemUser);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->set('selectedBandId', $band->id)
        ->set('selectedModeId', $mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect(OperatingSession::where('operator_user_id', $systemUser->id)->exists())->toBeFalse();
});
