<?php

use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifFieldMapperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->mapper = app(AdifFieldMapperService::class);

    // Seed reference data
    $this->band20m = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->band40m = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);
    $this->modeCw = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->modePhone = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->modeDigital = Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->sectionCt = Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
    $this->sectionNnj = Section::create(['code' => 'NNJ', 'name' => 'Northern New Jersey', 'region' => 'W2', 'is_active' => true]);
});

test('auto-maps bands by case-insensitive name match', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'band_name' => '20M',
    ]);

    $report = $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->band_id)->toBe($this->band20m->id)
        ->and($report['unmapped_bands'])->toBeEmpty();
});

test('reports unmapped bands', function () {
    $import = AdifImport::factory()->create();
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'band_name' => '12M',
    ]);

    $report = $this->mapper->autoMap($import);

    expect($report['unmapped_bands'])->toContain('12M');
});

test('maps ADIF mode CW to CW', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'mode_name' => 'CW',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->mode_id)->toBe($this->modeCw->id);
});

test('maps ADIF mode SSB to Phone', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'mode_name' => 'SSB',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->mode_id)->toBe($this->modePhone->id);
});

test('maps ADIF mode FM to Phone', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'mode_name' => 'FM',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->mode_id)->toBe($this->modePhone->id);
});

test('maps ADIF mode RTTY to Digital', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'mode_name' => 'RTTY',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->mode_id)->toBe($this->modeDigital->id);
});

test('maps ADIF mode FT8 to Digital', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'mode_name' => 'FT8',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->mode_id)->toBe($this->modeDigital->id);
});

test('auto-maps sections by code', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'section_code' => 'CT',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->section_id)->toBe($this->sectionCt->id);
});

test('auto-maps station by name match', function () {
    $config = EventConfiguration::factory()->create();
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'name' => 'DESKTOP-11-2024',
    ]);
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $config->id,
    ]);
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'station_identifier' => 'DESKTOP-11-2024',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->station_id)->toBe($station->id);
});

test('reports unmapped stations', function () {
    $import = AdifImport::factory()->create();
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'station_identifier' => 'UNKNOWN-STATION',
    ]);

    $report = $this->mapper->autoMap($import);

    expect($report['unmapped_stations'])->toContain('UNKNOWN-STATION');
});

test('auto-maps operator by callsign', function () {
    $user = User::factory()->create(['call_sign' => 'K3CPK']);
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'operator_callsign' => 'K3CPK',
    ]);

    $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->operator_user_id)->toBe($user->id);
});

test('detects class inconsistencies for same callsign', function () {
    $import = AdifImport::factory()->create();
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'section_code' => 'CT',
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '2A',
        'section_code' => 'CT',
    ]);

    $inconsistencies = $this->mapper->detectInconsistencies($import);

    expect($inconsistencies)->toHaveKey('W1AW')
        ->and($inconsistencies['W1AW']['exchange_class'])->toContain('3A')
        ->and($inconsistencies['W1AW']['exchange_class'])->toContain('2A');
});

test('detects section inconsistencies for same callsign', function () {
    $import = AdifImport::factory()->create();
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'section_code' => 'CT',
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'section_code' => 'NH',
    ]);

    $inconsistencies = $this->mapper->detectInconsistencies($import);

    expect($inconsistencies)->toHaveKey('W1AW')
        ->and($inconsistencies['W1AW']['section_code'])->toContain('CT')
        ->and($inconsistencies['W1AW']['section_code'])->toContain('NH');
});

test('no inconsistencies when callsign values are consistent', function () {
    $import = AdifImport::factory()->create();
    AdifImportRecord::factory()->count(3)->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'section_code' => 'CT',
    ]);

    $inconsistencies = $this->mapper->detectInconsistencies($import);

    expect($inconsistencies)->toBeEmpty();
});

test('applies user resolution for inconsistencies', function () {
    $import = AdifImport::factory()->create();
    $record1 = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
    ]);
    $record2 = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'exchange_class' => '2A',
    ]);

    $this->mapper->applyResolutions($import, [
        'W1AW' => ['exchange_class' => '3A'],
    ]);

    $record1->refresh();
    $record2->refresh();
    expect($record1->exchange_class)->toBe('3A')
        ->and($record2->exchange_class)->toBe('3A');
});

test('applies user-provided band mapping', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'band_name' => '12M',
        'band_id' => null,
    ]);

    $this->mapper->applyFieldMapping($import, [
        'bands' => ['12M' => $this->band20m->id],
    ]);

    $record->refresh();
    expect($record->band_id)->toBe($this->band20m->id);
});

test('applies user-provided station mapping', function () {
    $config = EventConfiguration::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $config->id]);
    $import = AdifImport::factory()->create(['event_configuration_id' => $config->id]);
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'station_identifier' => 'MYSTERY-PC',
        'station_id' => null,
    ]);

    $this->mapper->applyFieldMapping($import, [
        'stations' => ['MYSTERY-PC' => $station->id],
    ]);

    $record->refresh();
    expect($record->station_id)->toBe($station->id);
});

test('auto-creates stub user for unknown operator callsign', function () {
    $import = AdifImport::factory()->create();
    $record = AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'operator_callsign' => 'N0ACCT',
    ]);

    $report = $this->mapper->autoMap($import);

    $record->refresh();
    expect($record->operator_user_id)->not->toBeNull();

    $stubUser = User::where('call_sign', 'N0ACCT')->first();
    expect($stubUser)->not->toBeNull()
        ->and($stubUser->user_role)->toBe('locked');
});
