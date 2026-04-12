<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Services\CabrilloExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCabrilloExporterConfig(array $configOverrides = [], array $eventOverrides = []): EventConfiguration
{
    $section = Section::factory()->create(['code' => 'CT']);

    $eventType = EventType::factory()->create(['code' => 'FD', 'name' => 'Field Day', 'is_active' => true]);

    $opClass = OperatingClass::create([
        'code' => 'A',
        'event_type_id' => $eventType->id,
        'name' => 'Class A',
        'description' => 'Portable emergency power',
        'allows_gota' => false,
        'allows_free_vhf' => false,
        'requires_emergency_power' => false,
    ]);

    $event = Event::factory()->create(array_merge([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ], $eventOverrides));

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'Anytown ARC',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'transmitter_count' => 2,
        'max_power_watts' => 100,
    ], $configOverrides));
}

test('includes required cabrillo header fields', function () {
    $config = makeCabrilloExporterConfig();
    $output = app(CabrilloExporter::class)->export($config);

    expect($output)
        ->toContain('START-OF-LOG: 3.0')
        ->toContain('CREATED-BY: FD Commander')
        ->toContain('CONTEST: ARRL-FD')
        ->toContain('CALLSIGN: W1AW')
        ->toContain('LOCATION: CT')
        ->toContain('CATEGORY-OPERATOR: MULTI-OP')
        ->toContain('CATEGORY-BAND: ALL')
        ->toContain('CATEGORY-MODE: MIXED')
        ->toContain('CATEGORY-STATION: PORTABLE')
        ->toContain('CLUB: Anytown ARC')
        ->toContain('END-OF-LOG:');
});

test('maps power to HIGH when over 100 watts', function () {
    $config = makeCabrilloExporterConfig(['max_power_watts' => 1500]);
    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('CATEGORY-POWER: HIGH');
});

test('maps power to LOW for 6-100 watts', function () {
    $config = makeCabrilloExporterConfig(['max_power_watts' => 100]);
    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('CATEGORY-POWER: LOW');
});

test('maps power to QRP for 5 watts or less', function () {
    $config = makeCabrilloExporterConfig(['max_power_watts' => 5]);
    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('CATEGORY-POWER: QRP');
});

test('formats a CW qso line correctly', function () {
    $config = makeCabrilloExporterConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.0]);
    $mode = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);
    $meSection = Section::factory()->create(['code' => 'ME']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $meSection->id,
        'qso_time' => '2025-06-28 14:23:00',
        'callsign' => 'K1ABC',
        'exchange_class' => '1A',
        'is_duplicate' => false,
    ]);

    $output = app(CabrilloExporter::class)->export($config);

    expect($output)->toContain('QSO: 14000 CW 2025-06-28 1423 W1AW          2A   CT    K1ABC         1A   ME');
});

test('maps phone mode to PH', function () {
    $config = makeCabrilloExporterConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.0]);
    $mode = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $meSection = Section::factory()->create(['code' => 'ME']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $meSection->id,
        'qso_time' => '2025-06-28 14:23:00',
        'callsign' => 'K1ABC',
        'exchange_class' => '1A',
        'is_duplicate' => false,
    ]);

    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('QSO: 14000 PH 2025-06-28 1423 W1AW          2A   CT    K1ABC         1A   ME');
});

test('maps digital mode to DG', function () {
    $config = makeCabrilloExporterConfig();
    $band = Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14.0]);
    $mode = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital']);
    $nnySection = Section::factory()->create(['code' => 'NNY']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $nnySection->id,
        'qso_time' => '2025-06-28 14:30:00',
        'callsign' => 'N2XYZ',
        'exchange_class' => '3A',
        'is_duplicate' => false,
    ]);

    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('QSO: 14000 DG 2025-06-28 1430 W1AW          2A   CT    N2XYZ         3A   NNY');
});

test('excludes duplicate contacts from qso lines', function () {
    $config = makeCabrilloExporterConfig();
    $band = Band::factory()->create(['frequency_mhz' => 14.0]);
    $mode = Mode::factory()->create(['category' => 'CW']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'callsign' => 'K1GOOD',
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'callsign' => 'K1DUPE',
        'is_duplicate' => true,
    ]);

    $output = app(CabrilloExporter::class)->export($config);
    expect($output)
        ->toContain('K1GOOD')
        ->not->toContain('K1DUPE');
});

test('generates a correct filename', function () {
    $config = makeCabrilloExporterConfig([], ['start_time' => '2025-06-28 12:00:00']);
    $filename = app(CabrilloExporter::class)->filename($config);
    expect($filename)->toBe('w1aw-2025-field-day.log');
});

test('uses frequency 0 for satellite band contacts', function () {
    $config = makeCabrilloExporterConfig();
    $satBand = Band::factory()->create(['name' => 'Satellite', 'frequency_mhz' => null]);
    $mode = Mode::factory()->create(['category' => 'CW']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $satBand->id,
        'mode_id' => $mode->id,
        'callsign' => 'K1SAT',
        'is_duplicate' => false,
    ]);

    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->toContain('QSO:     0 CW');
});

test('omits club line when club name is not set', function () {
    $config = makeCabrilloExporterConfig(['club_name' => null]);
    $output = app(CabrilloExporter::class)->export($config);
    expect($output)->not->toContain('CLUB:');
});

test('gota contacts use gota callsign in qso line', function () {
    $config = makeCabrilloExporterConfig([
        'callsign' => 'W1AW',
        'has_gota_station' => true,
        'gota_callsign' => 'K1GOT',
    ]);

    $ctSection = Section::where('code', 'CT')->first();
    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'section_id' => $ctSection->id,
        'callsign' => 'N1ABC',
        'exchange_class' => '2A',
        'is_duplicate' => false,
    ]);

    $exporter = new CabrilloExporter;
    $output = $exporter->export($config);

    // QSO line should use GOTA callsign
    $qsoLines = collect(explode("\r\n", $output))->filter(fn ($l) => str_starts_with($l, 'QSO:'));
    expect($qsoLines->first())->toContain('K1GOT');

    // SOAPBOX should mention GOTA
    expect($output)->toContain('SOAPBOX: GOTA Station Callsign: K1GOT');
});

test('non-gota contacts use primary callsign in qso line', function () {
    $config = makeCabrilloExporterConfig([
        'callsign' => 'W1AW',
        'has_gota_station' => true,
        'gota_callsign' => 'K1GOT',
    ]);

    $ctSection = Section::where('code', 'CT')->first();
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'section_id' => $ctSection->id,
        'callsign' => 'N1ABC',
        'exchange_class' => '2A',
        'is_gota_contact' => false,
        'is_duplicate' => false,
    ]);

    $exporter = new CabrilloExporter;
    $output = $exporter->export($config);

    $qsoLines = collect(explode("\r\n", $output))->filter(fn ($l) => str_starts_with($l, 'QSO:'));
    expect($qsoLines->first())->toContain('W1AW');
    expect($qsoLines->first())->not->toContain('K1GOT');
});
