<?php

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Services\AdifValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->validator = new AdifValidationService;

    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    // Create FD event type (id=1) with classes A-F
    $this->eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'ARRL Field Day', 'is_active' => true],
    );
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $code) {
        OperatingClass::firstOrCreate(
            ['code' => $code, 'event_type_id' => $this->eventType->id],
            ['name' => "Class {$code}"],
        );
    }

    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => Carbon::parse('2026-06-27 18:00:00'),
        'end_time' => Carbon::parse('2026-06-28 18:00:00'),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);
});

test('QSO within event window passes validation', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-06-28 01:00:00'),
        'exchange_class' => '3A',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->not->toBe(AdifRecordStatus::Invalid)
        ->and($result['invalid_count'])->toBe(0)
        ->and($result['valid_count'])->toBe(1);
});

test('QSO outside event window is flagged invalid', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-01-25 12:00:00'),
        'exchange_class' => '3A',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->toBe(AdifRecordStatus::Invalid)
        ->and($record->notes)->toContain('outside event window')
        ->and($result['invalid_count'])->toBe(1);
});

test('valid FD class code passes validation', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-06-28 01:00:00'),
        'exchange_class' => '3A',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->not->toBe(AdifRecordStatus::Invalid)
        ->and($result['invalid_count'])->toBe(0);
});

test('WFD class code against FD event is flagged invalid', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-06-28 01:00:00'),
        'exchange_class' => '1H',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->toBe(AdifRecordStatus::Invalid)
        ->and($record->notes)->toContain('not valid')
        ->and($result['invalid_count'])->toBe(1);
});

test('mixed valid and invalid records return correct counts', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);

    // Valid record
    AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-06-28 01:00:00'),
        'exchange_class' => '3A',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    // Invalid date
    AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W2WSX',
        'qso_time' => Carbon::parse('2026-01-25 12:00:00'),
        'exchange_class' => '1D',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    // Invalid class
    AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'K1ABC',
        'qso_time' => Carbon::parse('2026-06-28 02:00:00'),
        'exchange_class' => '2H',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    expect($result['invalid_count'])->toBe(2)
        ->and($result['valid_count'])->toBe(1);
});

test('record with null qso_time is flagged invalid', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => null,
        'exchange_class' => '3A',
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->toBe(AdifRecordStatus::Invalid)
        ->and($record->notes)->toContain('Missing QSO time');
});

test('record with null exchange_class passes validation', function () {
    $import = AdifImport::factory()->create(['event_configuration_id' => $this->config->id]);
    $record = AdifImportRecord::factory()->mapped()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => Carbon::parse('2026-06-28 01:00:00'),
        'exchange_class' => null,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    $result = $this->validator->validate($import);

    $record->refresh();
    expect($record->status)->not->toBe(AdifRecordStatus::Invalid)
        ->and($result['valid_count'])->toBe(1);
});
