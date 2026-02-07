<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new DuplicateCheckService;

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.175,
    ]);

    $this->otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create([
        'name' => '40m',
        'meters' => 40,
        'frequency_mhz' => 7.0,
    ]);

    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'Phone',
        'category' => 'Phone',
        'points_fd' => 1,
        'points_wfd' => 1,
    ]);

    $this->otherMode = Mode::where('name', '!=', $this->mode->name)->first() ?? Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
    ]);

    $this->eventConfig = EventConfiguration::factory()->create();
    $this->session = OperatingSession::factory()->create([
        'station_id' => \App\Models\Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ])->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
});

test('returns not duplicate when no matching contact exists', function () {
    $result = $this->service->check('W1AW', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse()
        ->and($result['duplicate_of_contact_id'])->toBeNull();
});

test('returns duplicate when same callsign band mode exists in event', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $result = $this->service->check('W1AW', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeTrue()
        ->and($result['duplicate_of_contact_id'])->toBe($contact->id);
});

test('not duplicate on different band', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $result = $this->service->check('W1AW', $this->otherBand->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse();
});

test('not duplicate on different mode', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $result = $this->service->check('W1AW', $this->band->id, $this->otherMode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse();
});

test('not duplicate across different events', function () {
    $otherEventConfig = EventConfiguration::factory()->create();

    Contact::factory()->create([
        'event_configuration_id' => $otherEventConfig->id,
        'operating_session_id' => OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $otherEventConfig->id,
            ])->id,
        ])->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $result = $this->service->check('W1AW', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse();
});

test('callsign matching is case insensitive', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $result = $this->service->check('w1aw', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeTrue();
});

test('ignores soft deleted contacts', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
    ]);

    $contact->delete(); // Soft delete

    $result = $this->service->check('W1AW', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse();
});

test('ignores contacts already marked as duplicates', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => true,
    ]);

    $result = $this->service->check('W1AW', $this->band->id, $this->mode->id, $this->eventConfig->id);

    expect($result['is_duplicate'])->toBeFalse();
});
