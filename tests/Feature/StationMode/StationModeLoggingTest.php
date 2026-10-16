<?php

use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);
    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->boundStation = Station::factory()->create(['event_configuration_id' => $eventConfig->id, 'name' => 'Bound Station']);
    $this->otherStation = Station::factory()->create(['event_configuration_id' => $eventConfig->id, 'name' => 'Other Station']);
    $this->device = StationDevice::factory()->create(['station_id' => $this->boundStation->id]);

    $this->ghost = User::factory()->ghost()->create();
    $this->actingAs($this->ghost)->withSession([StationMode::SESSION_KEY => $this->device->id]);
});

test('only the bound station is listed and its setup opens automatically', function () {
    Livewire::test(StationSelect::class)
        ->assertSee('Bound Station')
        ->assertDontSee('Other Station')
        ->assertSet('showSetupModal', true)
        ->assertSet('selectedStationId', $this->boundStation->id);
});

test('cannot start a session on another station', function () {
    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $this->otherStation->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasErrors('selectedStationId');

    expect(OperatingSession::count())->toBe(0);
});

test('starts a session on the bound station as the ghost operator', function () {
    Livewire::test(StationSelect::class)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->call('startSession')
        ->assertHasNoErrors();

    $session = OperatingSession::sole();

    expect($session->station_id)->toBe($this->boundStation->id)
        ->and($session->operator_user_id)->toBe($this->ghost->id);
});

test('ending the logging session signs the operator out', function () {
    $session = OperatingSession::factory()->active()->create([
        'station_id' => $this->boundStation->id,
        'operator_user_id' => $this->ghost->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $session])
        ->call('endSession')
        ->assertRedirect(route('station-mode.landing'));

    expect($session->fresh()->end_time)->not->toBeNull();
    $this->assertGuest();
});
