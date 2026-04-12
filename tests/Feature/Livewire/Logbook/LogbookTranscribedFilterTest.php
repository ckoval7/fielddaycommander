<?php

use App\Livewire\Logbook\LogbookBrowser;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $band = Band::first() ?? Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $mode = Mode::first() ?? Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $section = Section::first() ?? Section::factory()->create();

    $event = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $station = $event->eventConfiguration->stations->first();
    $this->eventConfigId = $event->eventConfiguration->id;

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_transcription' => false,
    ]);

    $transcribeSession = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => null,
        'mode_id' => null,
        'is_transcription' => true,
    ]);

    $this->liveContact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfigId,
        'operating_session_id' => $session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'callsign' => 'K1LIVE',
        'is_transcribed' => false,
    ]);

    $this->transcribedContact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfigId,
        'operating_session_id' => $transcribeSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'callsign' => 'K1TXCR',
        'is_transcribed' => true,
    ]);
});

test('transcribed filter shows only transcribed contacts', function () {
    $this->actingAs($this->user);

    Livewire::test(LogbookBrowser::class)
        ->set('eventConfigurationId', $this->eventConfigId)
        ->set('showTranscribed', 'only')
        ->assertSee('K1TXCR')
        ->assertDontSee('K1LIVE');
});

test('transcribed filter off shows all contacts', function () {
    $this->actingAs($this->user);

    Livewire::test(LogbookBrowser::class)
        ->set('eventConfigurationId', $this->eventConfigId)
        ->assertSee('K1LIVE')
        ->assertSee('K1TXCR');
});
