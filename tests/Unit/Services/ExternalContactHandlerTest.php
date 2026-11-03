<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\DuplicateCheckService;
use App\Services\ExternalContactHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Event::fake();

    // Use a test timestamp within the event window (30-31 days from now)
    $this->testTimestamp = now()->addDays(30)->setTime(19, 0, 0);

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
        timestamp: $this->testTimestamp,
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
        ->and($contact->external_id)->toBe('abc123def456')
        ->and($contact->external_source)->toBe('n1mm')
        ->and($contact->logger_user_id)->toBe($this->user->id);

    Event::assertDispatched(ExternalContactReceived::class);
});

test('creates operating session for contact', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
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
        'qso_time' => $this->testTimestamp,
        'is_duplicate' => false,
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp->copy()->addMinutes(17),
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

test('handles contact replace by external_id', function () {
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
        'external_id' => 'replace123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
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
    Event::assertDispatched(ExternalContactUpdated::class, function (ExternalContactUpdated $event) use ($contact): bool {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->contact->is($contact)
            && $event->broadcastAs() === 'ContactUpdated'
            && in_array("private-event.{$this->config->event_id}", $channels, true)
            && in_array("private-event.{$this->config->id}.external-logger", $channels, true);
    });
});

test('handles contact delete by external_id', function () {
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
        'external_id' => 'delete123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        externalId: 'delete123',
        isDelete: true,
    );

    $this->handler->handleDelete($dto, $this->config);

    expect(Contact::where('external_id', 'delete123')->first())->toBeNull()
        ->and(Contact::withTrashed()->where('external_id', 'delete123')->first())->not->toBeNull();

    Event::assertDispatched(ExternalContactDeleted::class, function (ExternalContactDeleted $event): bool {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->callsign === 'W1AW'
            && $event->broadcastAs() === 'ContactDeleted'
            && in_array("private-event.{$this->config->event_id}", $channels, true)
            && in_array("private-event.{$this->config->id}.external-logger", $channels, true);
    });
});

test('ignores delete for unknown external_id', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        externalId: 'nonexistent',
        isDelete: true,
    );

    $this->handler->handleDelete($dto, $this->config);

    Event::assertNotDispatched(ExternalContactDeleted::class);
});

test('treats duplicate contactinfo with same external_id as replace', function () {
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
        'external_id' => 'idempotent123',
        'external_source' => 'n1mm',
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        externalId: 'idempotent123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect(Contact::where('external_id', 'idempotent123')->count())->toBe(1);
});

test('handles RadioInfo by caching for later use', function () {
    $dto = new ExternalRadioInfoDto(
        stationIdentifier: 'CONTEST-PC',
        source: 'n1mm',
        operatorCallsign: 'K3CPK',
        frequencyHz: 3525190,
        modeName: 'CW',
    );

    $this->handler->handleRadioInfo($dto, $this->config);

    // RadioInfo should NOT create a session - only contacts do
    $session = OperatingSession::where('station_id', $this->station->id)
        ->whereNull('end_time')
        ->first();
    expect($session)->toBeNull();

    // RadioInfo should be cached for use when contacts arrive
    $cached = Cache::get('external_radio_info:n1mm:CONTEST-PC');
    expect($cached)->not->toBeNull()
        ->and($cached->operatorCallsign)->toBe('K3CPK')
        ->and($cached->frequencyHz)->toBe(3525190)
        ->and($cached->modeName)->toBe('CW');
});

test('contact uses cached RadioInfo for fallback operator/band/mode', function () {
    // First, send RadioInfo with operator and mode
    $radioDto = new ExternalRadioInfoDto(
        stationIdentifier: 'CONTEST-PC',
        source: 'n1mm',
        operatorCallsign: 'K3CPK',
        frequencyHz: 3525190,
        modeName: 'CW',
    );
    $this->handler->handleRadioInfo($radioDto, $this->config);

    // Then, send a contact WITHOUT operator/frequency/mode
    $contactDto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        stationIdentifier: 'CONTEST-PC',
        externalId: 'fallback123',
        sectionCode: 'CT',
    );

    $contact = $this->handler->handleContact($contactDto, $this->config);

    // Contact should use fallback values from cached RadioInfo
    expect($contact->logger_user_id)->toBe($this->user->id)
        ->and($contact->band_id)->toBe($this->band->id)
        ->and($contact->mode_id)->toBe($this->modeCw->id);
});

test('handles contact with null operator', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
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
        timestamp: $this->testTimestamp,
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
        timestamp: $this->testTimestamp,
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

test('flags contacts from the GOTA station as GOTA contacts and dupe-checks them separately', function () {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'GOTA-PC',
        'is_gota' => true,
    ]);
    $mainSession = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $mainSession->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'logger_user_id' => $this->user->id,
    ]);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'GOTA-PC',
        frequencyHz: 3525190,
        externalId: 'gota123',
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->is_gota_contact)->toBeTrue()
        ->and($contact->is_duplicate)->toBeFalse()
        ->and($contact->points)->toBe((int) $this->modeCw->points_fd);
});

test('retries a contact once when the event lock wait times out instead of dropping it', function () {
    $flakyDupes = new class extends DuplicateCheckService
    {
        public int $failuresLeft = 1;

        public function locked(?int $eventConfigurationId, Closure $callback): mixed
        {
            if ($this->failuresLeft-- > 0) {
                throw new QueryException('mysql', 'select 1', [], new PDOException(
                    'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'
                ));
            }

            return parent::locked($eventConfigurationId, $callback);
        }
    };
    app()->instance(DuplicateCheckService::class, $flakyDupes);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sectionCode: 'CT',
        externalId: 'retry-me',
    );

    $contact = app(ExternalContactHandler::class)->handleContact($dto, $this->config);

    expect($contact->exists)->toBeTrue()
        ->and(Contact::where('external_id', 'retry-me')->count())->toBe(1)
        // One failed attempt, then the retry's lock and save()'s nested lock.
        ->and($flakyDupes->failuresLeft)->toBe(-2);
});

test('does not retry errors other than lock contention', function () {
    $failingDupes = new class extends DuplicateCheckService
    {
        public int $calls = 0;

        public function locked(?int $eventConfigurationId, Closure $callback): mixed
        {
            $this->calls++;

            throw new RuntimeException('boom');
        }
    };
    app()->instance(DuplicateCheckService::class, $failingDupes);

    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sectionCode: 'CT',
        externalId: 'no-retry',
    );

    expect(fn () => app(ExternalContactHandler::class)->handleContact($dto, $this->config))
        ->toThrow(RuntimeException::class, 'boom')
        ->and($failingDupes->calls)->toBe(1);
});

/**
 * Seed YT, MX and the sections ARRL counts them as.
 */
function seedFoldedSections(): void
{
    Section::firstOrCreate(['code' => 'TER'], ['name' => 'Territories', 'region' => 'VE', 'is_active' => true]);
    Section::firstOrCreate(['code' => 'DX'], ['name' => 'DX', 'region' => 'DX', 'is_active' => true]);
    Section::firstOrCreate(['code' => 'YT'], ['name' => 'Yukon', 'region' => 'VE', 'is_active' => true]);
    Section::firstOrCreate(['code' => 'MX'], ['name' => 'Mexico', 'region' => 'DX', 'is_active' => true]);
}

test('stores YT and MX from an external logger as TER and DX for a Field Day event', function (string $logged, string $counted) {
    seedFoldedSections();

    $contact = $this->handler->handleContact(new ExternalContactDto(
        callsign: 'VY1AA',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sectionCode: $logged,
        externalId: "fold-{$logged}",
    ), $this->config);

    expect($contact->section_id)->toBe(Section::where('code', $counted)->value('id'));
})->with([
    'Yukon' => ['YT', 'TER'],
    'Mexico' => ['mx', 'DX'],
]);

test('stores YT and MX from an external logger as logged for a Winter Field Day event', function (string $logged) {
    seedFoldedSections();
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'description' => 'Winter Field Day']);
    $this->config->event->update(['event_type_id' => $wfd->id]);

    $contact = $this->handler->handleContact(new ExternalContactDto(
        callsign: 'VY1AA',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sectionCode: $logged,
        externalId: "keep-{$logged}",
    ), $this->config->fresh());

    expect($contact->section_id)->toBe(Section::where('code', $logged)->value('id'));
})->with(['YT', 'MX']);

test('a contact from an ADIF logger records its TX_PWR as the contact power', function () {
    $dto = new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'adif',
        modeName: 'CW',
        operatorCallsign: 'K3CPK',
        stationIdentifier: 'CONTEST-PC',
        frequencyHz: 3525190,
        sectionCode: 'CT',
        adifFields: ['TX_PWR' => '400'],
    );

    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->power_watts)->toBe(400)
        ->and($this->config->fresh()->highestContactPowerWatts())->toBe(400);
});

test('audits a contact an external logger deletes, naming the logger, station and address', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'exchange_class' => '2A',
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'external_id' => 'audit123',
        'external_source' => 'n1mm',
    ]);

    $this->handler->handleDelete(new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        stationIdentifier: 'RUN-PC',
        externalId: 'audit123',
        isDelete: true,
    ), $this->config, '192.168.1.40');

    $entry = AuditLog::where('action', 'contact.deleted')->sole();

    expect($entry->auditable_id)->toBe($contact->id)
        ->and($entry->ip_address)->toBe('192.168.1.40')
        ->and($entry->user_id)->toBeNull()
        ->and($entry->old_values)->toMatchArray(['callsign' => 'W1AW', 'exchange_class' => '2A', 'session_id' => $session->id])
        ->and($entry->new_values)->toBe([
            'changed_by' => 'external_logger',
            'source' => 'n1mm',
            'sender' => 'RUN-PC',
            'sender_ip' => '192.168.1.40',
        ]);
});

test('audits the fields an external logger edit changes, and only edits that change something', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'exchange_class' => '2A',
        'qso_time' => $this->testTimestamp,
        'band_id' => $this->band->id,
        'mode_id' => $this->modeCw->id,
        'submode' => 'CW',
        'external_id' => 'edit123',
        'external_source' => 'n1mm',
    ]);
    $replace = fn (string $class) => new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: 'RUN-PC',
        exchangeClass: $class,
        externalId: 'edit123',
        isReplace: true,
    );

    $this->handler->handleReplace($replace('2A'), $this->config, '192.168.1.40');
    expect(AuditLog::where('action', 'contact.updated')->count())->toBe(0);

    $this->handler->handleReplace($replace('3A'), $this->config, '192.168.1.40');

    expect(AuditLog::where('action', 'contact.updated')->sole())
        ->auditable_id->toBe($contact->id)
        ->ip_address->toBe('192.168.1.40')
        ->old_values->toBe(['exchange_class' => '2A'])
        ->new_values->toBe([
            'exchange_class' => '3A',
            'changed_by' => 'external_logger',
            'source' => 'n1mm',
            'sender' => 'RUN-PC',
            'sender_ip' => '192.168.1.40',
        ]);
});

test('falls back to the logger band when the frequency is in no known band', function () {
    $contact = $this->handler->handleContact(new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        bandName: $this->band->name,
        modeName: 'CW',
        stationIdentifier: $this->station->name,
        frequencyHz: 10_120_000,
        externalId: 'band123',
    ), $this->config);

    expect($contact->band_id)->toBe($this->band->id);

    $this->handler->handleReplace(new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->testTimestamp,
        source: 'n1mm',
        modeName: 'CW',
        stationIdentifier: $this->station->name,
        frequencyHz: 10_120_000,
        externalId: 'band123',
        isReplace: true,
    ), $this->config);

    expect($contact->fresh()->band_id)->toBe($this->band->id);
});

/**
 * An N1MM contact DTO for this test's event.
 */
function n1mmDto(object $test, string $id, array $overrides = []): ExternalContactDto
{
    return new ExternalContactDto(...[
        'callsign' => 'W1AW',
        'timestamp' => $test->testTimestamp,
        'source' => 'n1mm',
        'bandName' => $test->band->name,
        'modeName' => 'CW',
        'stationIdentifier' => $test->station->name,
        'externalId' => $id,
        ...$overrides,
    ]);
}

test('stores a replace for a QSO it has never seen, as N1MM\'s rebroadcast sends', function () {
    $this->handler->handleReplace(n1mmDto($this, 'rebroadcast1', ['isReplace' => true]), $this->config);

    expect(Contact::where('external_id', 'rebroadcast1')->sole())
        ->callsign->toBe('W1AW')
        ->band_id->toBe($this->band->id);
});

test('deletes by callsign and time when a relayed delete carries a new id', function () {
    $contact = $this->handler->handleContact(n1mmDto($this, 'original1'), $this->config);

    $this->handler->handleDelete(n1mmDto($this, 'random-relay-id', ['isDelete' => true, 'bandName' => null]), $this->config);

    expect($contact->fresh()->trashed())->toBeTrue();
});

test('leaves contacts alone when an unknown delete matches more than one', function () {
    $first = $this->handler->handleContact(n1mmDto($this, 'one'), $this->config);
    $second = $this->handler->handleContact(n1mmDto($this, 'two'), $this->config);

    $this->handler->handleDelete(n1mmDto($this, 'unknown', ['isDelete' => true]), $this->config);

    expect($first->fresh()->trashed())->toBeFalse()
        ->and($second->fresh()->trashed())->toBeFalse();
});
