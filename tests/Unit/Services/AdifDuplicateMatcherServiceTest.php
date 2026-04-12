<?php

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
use App\Services\AdifDuplicateMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->matcher = new AdifDuplicateMatcherService;

    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()->create();
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id]);
    $this->user = User::factory()->create();
    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);
});

test('marks record as ready when no matching contact exists', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $summary = $this->matcher->match($import);

    $record->refresh();
    expect($record->status)->toBe(AdifRecordStatus::Ready)
        ->and($record->matched_contact_id)->toBeNull()
        ->and($summary['new'])->toBe(1);
});

test('matches contact within 10 minute window', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:05:00'),
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $summary = $this->matcher->match($import);

    $record->refresh();
    expect($record->matched_contact_id)->toBe($contact->id)
        ->and($record->status)->toBe(AdifRecordStatus::DuplicateMatch);
});

test('does not match contact outside 10 minute window', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:15:00'),
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $summary = $this->matcher->match($import);

    $record->refresh();
    expect($record->matched_contact_id)->toBeNull()
        ->and($record->status)->toBe(AdifRecordStatus::Ready)
        ->and($summary['new'])->toBe(1);
});

test('does not match contact on different band', function () {
    $band40m = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $band40m->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $this->matcher->match($import);

    $record->refresh();
    expect($record->matched_contact_id)->toBeNull()
        ->and($record->status)->toBe(AdifRecordStatus::Ready);
});

test('identifies merge candidates when existing contact has null fields', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'power_watts' => null,
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $summary = $this->matcher->match($import);

    expect($summary['merge'])->toBe(1);
});

test('identifies exact duplicates when all fields match', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
        'power_watts' => 100,
        'exchange_class' => '3A',
        'notes' => null,
        'logger_user_id' => $this->user->id,
    ]);

    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    $summary = $this->matcher->match($import);

    $record->refresh();
    expect($record->status)->toBe(AdifRecordStatus::Skipped)
        ->and($summary['skip'])->toBe(1);
});

test('returns correct summary counts', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);

    // New contact (no match)
    AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W2WSX',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 12:00:00'),
    ]);

    // Will match existing
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 13:00:00'),
        'power_watts' => null,
        'logger_user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => Carbon::parse('2026-04-10 13:02:00'),
    ]);

    $summary = $this->matcher->match($import);

    expect($summary['new'])->toBe(1)
        ->and($summary['merge'])->toBe(1);
});
