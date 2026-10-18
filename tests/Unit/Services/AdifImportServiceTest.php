<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
use App\Scoring\DomainEvents\QsoLogged;
use App\Services\AdifImportService;
use App\Services\DuplicateCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->service = new AdifImportService;

    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()->create();
    $this->config->event->update([
        'start_time' => Carbon::parse('2026-04-10 00:00:00'),
        'end_time' => Carbon::parse('2026-04-11 23:59:00'),
    ]);
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
        'exchange_class' => null,
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
    expect($contact->exchange_class)->toBe('3A')
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
        'exchange_class' => '3A',
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

test('builds correct exchange_class from ADIF fields', function () {
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
    expect($contact->exchange_class)->toBe('3A');
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

test('flags contacts imported for the GOTA station as GOTA contacts', function () {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_gota' => true,
    ]);
    $mainSession = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $mainSession->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
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
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    $contact = Contact::where('callsign', 'W1AW')->where('is_imported', true)->firstOrFail();
    expect($contact->is_gota_contact)->toBeTrue()
        ->and($contact->is_duplicate)->toBeFalse()
        ->and($contact->points)->toBe(5);
});

test('logs the exception when an import fails', function () {
    Log::spy();

    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);

    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W2WSX',
        'qso_time' => Carbon::parse('2026-04-10 12:05:00'),
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => null,
        'operator_user_id' => null,
        'status' => AdifRecordStatus::Ready,
    ]);

    $this->service->import($import);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ADIF import failed'
            && $context['adif_import_id'] === $import->id
            && $context['exception'] instanceof Throwable);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function readyAdifRecord(AdifImport $import, array $overrides = []): AdifImportRecord
{
    return AdifImportRecord::factory()->create(array_merge([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'band_id' => test()->band->id,
        'mode_id' => test()->mode->id,
        'section_id' => test()->section->id,
        'station_id' => test()->station->id,
        'operator_user_id' => test()->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ], $overrides));
}

test('an imported contact logged before an existing one becomes the original and demotes it', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
    $existing = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 15:00:00'),
        'points' => 1,
        'is_duplicate' => false,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    $record = readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 12:00:00')]);

    $this->service->import($import);

    $imported = Contact::where('is_imported', true)->firstOrFail();
    $existing->refresh();

    expect($imported->is_duplicate)->toBeFalse()
        ->and($imported->points)->toBe(1)
        ->and($existing->is_duplicate)->toBeTrue()
        ->and($existing->duplicate_of_contact_id)->toBe($imported->id)
        ->and($existing->points)->toBe(0)
        ->and($record->fresh()->status)->toBe(AdifRecordStatus::Imported)
        ->and($record->fresh()->notes)->toBeNull();
});

test('an imported contact logged after an existing one is a zero-point dupe and the record says so', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
    $existing = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 10:00:00'),
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    $record = readyAdifRecord($import, ['callsign' => 'w1aw']);

    $this->service->import($import);

    $imported = Contact::where('is_imported', true)->firstOrFail();

    expect($imported->is_duplicate)->toBeTrue()
        ->and($imported->duplicate_of_contact_id)->toBe($existing->id)
        ->and($imported->points)->toBe(0)
        ->and($existing->fresh()->is_duplicate)->toBeFalse()
        ->and($record->fresh()->notes)->toContain('duplicate');
});

test('dupes within one import file are settled by qso time, not file order', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    $later = readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 18:00:00')]);
    $earlier = readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 09:00:00')]);
    readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 20:00:00')]);

    $this->service->import($import);

    $contacts = Contact::where('is_imported', true)->orderBy('qso_time')->get();
    $original = $contacts->first();

    expect($contacts)->toHaveCount(3)
        ->and($original->qso_time->format('H:i'))->toBe('09:00')
        ->and($original->is_duplicate)->toBeFalse()
        ->and($original->points)->toBe(1)
        ->and($contacts->slice(1)->every(fn (Contact $contact) => $contact->is_duplicate
            && $contact->points === 0
            && $contact->duplicate_of_contact_id === $original->id))->toBeTrue()
        ->and($earlier->fresh()->notes)->toBeNull()
        ->and($later->fresh()->notes)->toContain('duplicate')
        ->and($import->fresh()->imported_records)->toBe(3)
        ->and(OperatingSession::sum('qso_count'))->toBe(3);
});

test('an out-of-period imported contact earns 0 points and does not take the original\'s place', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    $outside = readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-09 12:00:00')]);
    readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 12:00:00')]);

    $this->service->import($import);

    $outOfPeriod = Contact::where('qso_time', Carbon::parse('2026-04-09 12:00:00'))->firstOrFail();
    $inPeriod = Contact::where('qso_time', Carbon::parse('2026-04-10 12:00:00'))->firstOrFail();

    expect($outOfPeriod->points)->toBe(0)
        ->and($outOfPeriod->is_duplicate)->toBeFalse()
        ->and($inPeriod->points)->toBe(1)
        ->and($inPeriod->is_duplicate)->toBeFalse()
        ->and($outside->fresh()->notes)->toContain('outside the event period');
});

test('an import reconciles bonuses once, after the contacts are settled', function () {
    Event::fake([QsoLogged::class]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 18:00:00')]);
    readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 09:00:00')]);
    readyAdifRecord($import, ['callsign' => 'K1ABC']);

    $this->service->import($import);

    expect(Contact::where('is_imported', true)->where('points', 1)->count())->toBe(2);
    Event::assertDispatchedTimes(QsoLogged::class, 1);
});

test('dupe groups spanning batches are settled against earlier batches', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);

    foreach (range(1, 100) as $minute) {
        readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 12:00:00')->addMinutes($minute)]);
    }
    $earliest = readyAdifRecord($import, ['qso_time' => Carbon::parse('2026-04-10 08:00:00')]);
    $firstBatchRecord = AdifImportRecord::where('adif_import_id', $import->id)->orderBy('id')->first();

    $this->service->import($import);

    $original = Contact::where('qso_time', Carbon::parse('2026-04-10 08:00:00'))->firstOrFail();

    expect($import->fresh()->status)->toBe(AdifImportStatus::Completed)
        ->and($import->fresh()->imported_records)->toBe(101)
        ->and($original->is_duplicate)->toBeFalse()
        ->and(Contact::where('is_duplicate', true)->where('duplicate_of_contact_id', $original->id)->count())->toBe(100)
        ->and($earliest->fresh()->notes)->toBeNull()
        ->and($firstBatchRecord->fresh()->notes)->toContain("duplicate of contact #{$original->id}");
});

test('an unmapped record in a later batch fails the import before anything is written', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);

    foreach (range(1, 100) as $minute) {
        readyAdifRecord($import, ['callsign' => "K{$minute}ABC"]);
    }
    readyAdifRecord($import, ['station_id' => null]);

    $this->service->import($import);

    expect($import->fresh()->status)->toBe(AdifImportStatus::Failed)
        ->and(Contact::count())->toBe(0);
});

test('sessions an import creates are closed, spanning its first to last imported QSO', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);
    readyAdifRecord($import, ['callsign' => 'K1AAA', 'qso_time' => Carbon::parse('2026-04-10 18:00:00')]);
    readyAdifRecord($import, ['callsign' => 'K1BBB', 'qso_time' => Carbon::parse('2026-04-10 09:00:00')]);
    readyAdifRecord($import, ['callsign' => 'K1CCC', 'qso_time' => Carbon::parse('2026-04-10 13:30:00')]);

    $this->service->import($import);

    $session = OperatingSession::sole();

    expect(OperatingSession::whereNull('end_time')->count())->toBe(0)
        ->and($session->start_time->format('Y-m-d H:i'))->toBe('2026-04-10 09:00')
        ->and($session->end_time->format('Y-m-d H:i'))->toBe('2026-04-10 18:00')
        ->and($session->qso_count)->toBe(3);
});

test('an import that fails part way leaves no open sessions behind', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);

    foreach (range(1, 100) as $minute) {
        readyAdifRecord($import, ['callsign' => "K{$minute}ABC", 'qso_time' => Carbon::parse('2026-04-10 12:00:00')->addMinutes($minute)]);
    }
    readyAdifRecord($import, ['callsign' => 'W9BAD', 'qso_time' => Carbon::parse('2026-04-10 20:00:00')]);

    // The second batch hits a database error after its contacts are written.
    $dupes = Mockery::mock(DuplicateCheckService::class)->makePartial();
    $dupes->shouldReceive('settleGroups')->once()->passthru();
    $dupes->shouldReceive('settleGroups')->andThrow(new RuntimeException('database went away'));

    (new AdifImportService($dupes))->import($import);

    expect($import->fresh()->status)->toBe(AdifImportStatus::Failed)
        ->and($import->fresh()->imported_records)->toBe(100)
        ->and(OperatingSession::whereNull('end_time')->count())->toBe(0);
});

test('an import with a spent time budget stops after a batch and resumes without writing twice', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->user->id]);

    foreach (range(1, 150) as $minute) {
        readyAdifRecord($import, ['callsign' => "K{$minute}ABC", 'qso_time' => Carbon::parse('2026-04-10 12:00:00')->addMinutes($minute)]);
    }

    expect($this->service->import($import, 0))->toBeFalse()
        ->and(Contact::count())->toBe(100)
        ->and($import->fresh()->status)->toBe(AdifImportStatus::Importing)
        ->and($import->fresh()->progress_processed)->toBe(100)
        ->and($import->fresh()->progress_total)->toBe(150);

    expect((new AdifImportService)->import($import->fresh(), 0))->toBeFalse();
    expect((new AdifImportService)->import($import->fresh(), 0))->toBeTrue();

    expect(Contact::count())->toBe(150)
        ->and($import->fresh()->status)->toBe(AdifImportStatus::Completed)
        ->and($import->fresh()->imported_records)->toBe(150)
        ->and(OperatingSession::count())->toBe(1)
        ->and(OperatingSession::sole()->qso_count)->toBe(150);
});
