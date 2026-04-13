<?php

use App\Events\ExternalContactReceived;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);

    $this->mode = Mode::where('name', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->config = EventConfiguration::factory()->create();
    $this->event = $this->config->event;

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $this->user = User::factory()->create();

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);

    $this->contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);
});

test('ExternalContactReceived broadcasts on ContactLogged channel', function () {
    $event = new ExternalContactReceived($this->contact, $this->config->id, 'n1mm', $this->event->id);
    $channels = $event->broadcastOn();

    $channelNames = array_map(fn ($ch) => $ch->name, $channels);

    expect($channelNames)->toContain("private-event.{$this->event->id}");
});

test('ExternalContactReceived broadcasts as ContactLogged', function () {
    $event = new ExternalContactReceived($this->contact, $this->config->id, 'n1mm', $this->event->id);

    expect($event->broadcastAs())->toBe('ContactLogged');
});

test('ExternalContactReceived still broadcasts on external-logger channel', function () {
    $event = new ExternalContactReceived($this->contact, $this->config->id, 'n1mm', $this->event->id);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(2);

    $channelNames = array_map(fn ($ch) => $ch->name, $channels);
    expect($channelNames)->toContain("private-event.{$this->config->id}.external-logger");
});
