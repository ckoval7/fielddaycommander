<?php

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Events\ExternalStationStatusChanged;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\ExternalContactHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Event::fake();

    $this->config = EventConfiguration::factory()->create(['callsign' => 'W2XYZ']);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'CONTEST-PC',
    ]);
    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);
    $this->band = Band::firstOrCreate(['name' => '80m'], ['meters' => 80, 'frequency_mhz' => 3.5, 'sort_order' => 2]);
    $this->modeCw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->handler = app(ExternalContactHandler::class);
});

test('creates contact from ExternalContactDto', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sentReport: '599',
        receivedReport: '599',
        sectionCode: 'CT',
        externalId: 'abc123def456',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($contact->callsign)->toBe('W1AW')
        ->and($contact->band_id)->toBe($this->band->id)
        ->and($contact->mode_id)->toBe($this->modeCw->id)
        ->and($contact->section_id)->toBe($this->section->id)
        ->and($contact->n1mm_id)->toBe('abc123def456')
        ->and($contact->external_source)->toBe('n1mm')
        ->and($contact->logger_user_id)->toBe($this->user->id);

    Event::assertDispatched(ExternalContactReceived::class);
});

test('creates operating session for contact', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'abc123',
    );

    $this->handler->handleContact($dto, $this->config);

    $session = OperatingSession::where('station_id', $this->station->id)->first();
    expect($session)->not->toBeNull()
        ->and($session->external_source)->toBe('n1mm')
        ->and($session->last_activity_at)->not->toBeNull();
});

test('runs duplicate check on new contacts', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'is_duplicate' => false,
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 19:00:00'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'dupe123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->is_duplicate)->toBeTrue()
        ->and($contact->points)->toBe(0);
});

test('handles contact replace by n1mm_id', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AX',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'n1mm_id' => 'replace123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'replace123',
        isReplace: true,
        oldCallsign: 'W1AX',
    );

    $this->handler->handleReplace($dto, $this->config);

    $contact->refresh();
    expect($contact->callsign)->toBe('W1AW');

    Event::assertDispatched(ExternalContactUpdated::class);
});

test('handles contact delete by n1mm_id', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'n1mm_id' => 'delete123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        externalId: 'delete123',
        isDelete: true,
    );

    $this->handler->handleDelete($dto, $this->config);

    expect(Contact::where('n1mm_id', 'delete123')->first())->toBeNull()
        ->and(Contact::withTrashed()->where('n1mm_id', 'delete123')->first())->not->toBeNull();

    Event::assertDispatched(ExternalContactDeleted::class);
});

test('ignores delete for unknown n1mm_id', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        externalId: 'nonexistent',
        isDelete: true,
    );

    $this->handler->handleDelete($dto, $this->config);

    Event::assertNotDispatched(ExternalContactDeleted::class);
});

test('treats duplicate contactinfo with same n1mm_id as replace', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'n1mm_id' => 'idempotent123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'idempotent123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect(Contact::where('n1mm_id', 'idempotent123')->count())->toBe(1);
});

test('handles RadioInfo by opening new session', function () {
    $dto = new ExternalRadioInfoDto(
        stationIdentifier: 'CONTEST-PC',
        source: 'n1mm',
        operatorCallsign: 'K3CPK',
        frequencyHz: 3525190,
        modeName: 'CW',
    );

    $this->handler->handleRadioInfo($dto, $this->config);

    $session = OperatingSession::where('station_id', $this->station->id)
        ->whereNull('end_time')
        ->first();

    expect($session)->not->toBeNull()
        ->and($session->operator_user_id)->toBe($this->user->id)
        ->and($session->band_id)->toBe($this->band->id)
        ->and($session->mode_id)->toBe($this->modeCw->id)
        ->and($session->external_source)->toBe('n1mm');

    Event::assertDispatched(ExternalStationStatusChanged::class);
});

test('handles contact with null operator', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: null,
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'noop123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->logger_user_id)->toBeNull();
});

test('auto-creates stub user for unknown operator callsign', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'N0ACCT',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'stub123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    $stubUser = User::where('call_sign', 'N0ACCT')->first();
    expect($stubUser)->not->toBeNull()
        ->and($stubUser->user_role)->toBe('locked')
        ->and($contact->logger_user_id)->toBe($stubUser->id);
});

test('auto-creates station for unknown identifier', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: Carbon::parse('2026-06-28 18:43:38'),
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'BRAND-NEW-PC',
        frequencyHz: 3525190,
        externalId: 'new123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    $station = Station::where('name', 'BRAND-NEW-PC')->first();
    expect($station)->not->toBeNull()
        ->and($station->hostname)->toBe('BRAND-NEW-PC');
});
