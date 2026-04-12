<?php

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifImportService;
use App\Services\SessionResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->service = new AdifImportService(new SessionResolverService);

    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()->create();
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id]);
    $this->user = User::factory()->create();
});

test('creates new contacts for ready records', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $import->refresh();
    expect($import->status)->toBe(AdifImportStatus::Completed)
        ->and($import->imported_records)->toBe(1)
        ->and(Contact::where('callsign', 'W1AW')->exists())->toBeTrue();
});

test('creates operating session when needed', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $session = OperatingSession::where('station_id', $this->station->id)
        ->where('band_id', $this->band->id)
        ->where('mode_id', $this->mode->id)
        ->first();

    expect($session)->not->toBeNull()
        ->and($session->is_transcription)->toBeFalse()
        ->and($session->operator_user_id)->toBe($this->user->id);
});

test('reuses existing operating session for same band mode station operator', function () {
    $existingSession = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
    ]);

    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $contact = Contact::where('callsign', 'W1AW')->first();
    expect($contact->operating_session_id)->toBe($existingSession->id);
});

test('merges fields into matched contacts without overwriting', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'power_watts' => null,
        'received_exchange' => 'W1AW 3A CT',
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'matched_contact_id' => $contact->id,
        'raw_data' => ['CALL' => 'W1AW', 'FREQ' => '14.20000'],
        'status' => AdifRecordStatus::DuplicateMatch,
    ]);

    $this->service->import($import);

    $contact->refresh();
    $import->refresh();
    expect($contact->received_exchange)->toBe('W1AW 3A CT')
        ->and($import->merged_records)->toBe(1);
});

test('does not overwrite existing non-null fields during merge', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'power_watts' => 50,
        'received_exchange' => 'W1AW 3A CT',
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'matched_contact_id' => $contact->id,
        'raw_data' => ['CALL' => 'W1AW'],
        'status' => AdifRecordStatus::DuplicateMatch,
    ]);

    $this->service->import($import);

    $contact->refresh();
    expect($contact->power_watts)->toBe(50);
});

test('skips records with skipped status', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'status' => AdifRecordStatus::Skipped,
    ]);

    $this->service->import($import);

    $import->refresh();
    expect($import->skipped_records)->toBe(1)
        ->and($import->imported_records)->toBe(0);
});

test('rolls back on failure', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);

    // First record is valid
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    // Second record has no station — will fail
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W2WSX',
        'qso_time' => Carbon::parse('2026-04-10 12:05:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => null,
        'operator_user_id' => null,
        'exchange_class' => '1D',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $import->refresh();
    expect($import->status)->toBe(AdifImportStatus::Failed)
        ->and(Contact::where('callsign', 'W1AW')->exists())->toBeFalse();
});

test('sets import status to completed on success', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $import->refresh();
    expect($import->status)->toBe(AdifImportStatus::Completed);
});

test('builds correct received_exchange from ADIF fields', function () {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'section_code' => 'CT',
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $contact = Contact::where('callsign', 'W1AW')->first();
    expect($contact->received_exchange)->toBe('W1AW 3A CT');
});

test('sets logger_user_id to the ADIF operator, not the importing user', function () {
    $operator = User::factory()->create();

    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->station->id,
        'operator_user_id' => $operator->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $contact = Contact::where('callsign', 'W1AW')->first();
    expect($contact->logger_user_id)->toBe($operator->id)
        ->and($contact->logger_user_id)->not->toBe($this->user->id);
});
