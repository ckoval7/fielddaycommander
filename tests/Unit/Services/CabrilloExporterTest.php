<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\User;
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
        ->toContain('CREATED-BY: Field Day Commander')
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
    $config = makeCabrilloExporterConfig([], ['start_time' => '2025-06-28 12:00:00', 'end_time' => '2025-06-29 18:00:00']);
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
    $config = makeCabrilloExporterConfig([], ['start_time' => '2025-06-28 12:00:00', 'end_time' => '2025-06-29 18:00:00']);
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
    $config = makeCabrilloExporterConfig([], ['start_time' => '2025-06-28 12:00:00', 'end_time' => '2025-06-29 18:00:00']);
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

test('uses band designators for microwave bands and kHz below 1.2 GHz', function () {
    $config = makeCabrilloExporterConfig();
    $mode = Mode::factory()->create(['category' => 'CW']);

    foreach (['23cm' => 1240, '3cm' => 10000, '630m' => 0.472, '2m' => 144] as $name => $mhz) {
        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'band_id' => Band::factory()->create(['name' => $name, 'frequency_mhz' => $mhz])->id,
            'mode_id' => $mode->id,
            'callsign' => 'K1'.strtoupper(substr($name, 0, 2)),
            'is_duplicate' => false,
        ]);
    }

    $output = app(CabrilloExporter::class)->export($config);

    expect($output)->toContain('QSO:  1.2G CW')
        ->and($output)->toContain('QSO:   10G CW')
        ->and($output)->toContain('QSO:   472 CW')
        ->and($output)->toContain('QSO: 144000 CW');
});

// ---------------------------------------------------------------------------
// Winter Field Day 2027 (WFDA Cabrillo format, rules page 10)
// ---------------------------------------------------------------------------

function makeWfdCabrilloConfig(array $configOverrides = []): EventConfiguration
{
    $section = Section::where('code', 'WI')->first() ?? Section::factory()->create(['code' => 'WI']);
    $eventType = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'is_active' => true]);

    $opClass = OperatingClass::where('code', 'O')->where('event_type_id', $eventType->id)->first() ?? OperatingClass::create([
        'code' => 'O',
        'event_type_id' => $eventType->id,
        'name' => 'Outdoor',
        'description' => 'Outdoor',
        'allows_gota' => false,
        'allows_free_vhf' => false,
        'requires_emergency_power' => false,
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'rules_version' => '2027',
        'start_time' => '2027-01-23 16:00:00',
        'end_time' => '2027-01-24 18:00:00',
    ]);

    $creator = User::factory()->create([
        'first_name' => 'Fred',
        'last_name' => 'Hampton',
        'email' => 'fred@fakemail.com',
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'created_by_user_id' => $creator->id,
        'callsign' => 'K4SCO',
        'club_name' => 'Jackson County Amateur Radio',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'transmitter_count' => 3,
        'max_power_watts' => 100,
    ], $configOverrides));
}

function makeWfdCabrilloContact(EventConfiguration $config, string $category, array $attributes = []): Contact
{
    static $n = 0;
    $n++;

    return Contact::factory()->create(array_merge([
        'event_configuration_id' => $config->id,
        'band_id' => Band::firstOrCreate(['name' => '20m'], ['frequency_mhz' => 14.07, 'meters' => 20])->id,
        'mode_id' => Mode::firstOrCreate(['name' => $category], ['category' => $category])->id,
        'section_id' => (Section::where('code', 'MO')->first() ?? Section::factory()->create(['code' => 'MO']))->id,
        'callsign' => 'W4NR'.chr(64 + ($n % 26 + 1)),
        'exchange_class' => '2M',
        'qso_time' => '2027-01-23 21:31:00',
        'power_watts' => 5,
        'is_duplicate' => false,
        'is_satellite' => false,
        'submode' => null,
    ], $attributes));
}

test('wfd header follows the WFDA field set and order without soapbox or arrl categories', function () {
    $config = makeWfdCabrilloConfig();
    makeWfdCabrilloContact($config, 'CW');

    $lines = explode("\r\n", app(CabrilloExporter::class)->export($config));
    $headerKeys = collect($lines)->takeWhile(fn ($l) => ! str_starts_with($l, 'QSO:'))
        ->map(fn ($l) => explode(':', $l)[0])->all();

    expect($headerKeys)->toBe([
        'START-OF-LOG', 'CREATED-BY', 'LOCATION', 'CALLSIGN', 'CLUB', 'CONTEST', 'CATEGORY-POWER',
        'CLAIMED-SCORE', 'OPERATORS', 'NAME', 'X-EXCHANGE', 'EMAIL',
    ])->and($lines)->toContain('CONTEST: WFD', 'LOCATION: WI', 'CALLSIGN: K4SCO', 'NAME: Fred Hampton', 'EMAIL: fred@fakemail.com', 'X-EXCHANGE: 3O')
        ->and(implode("\n", $lines))->not->toContain('SOAPBOX')
        ->not->toContain('CATEGORY-OPERATOR')->not->toContain('CATEGORY-BAND')
        ->not->toContain('CATEGORY-MODE')->not->toContain('CATEGORY-STATION');
});

test('wfd qso lines use the wfd exchange', function () {
    $config = makeWfdCabrilloConfig();
    makeWfdCabrilloContact($config, 'CW', ['callsign' => 'W4NRR']);

    expect(app(CabrilloExporter::class)->export($config))
        ->toContain('QSO: 14070 CW 2027-01-23 2131 K4SCO         3O   WI    W4NRR         2M   MO');
});

test('wfd power category is QRP at 10W phone and LOW at 10W CW', function () {
    $phone = makeWfdCabrilloConfig();
    makeWfdCabrilloContact($phone, 'Phone', ['power_watts' => 10]);
    expect(app(CabrilloExporter::class)->export($phone))->toContain('CATEGORY-POWER: QRP');

    $cw = makeWfdCabrilloConfig(['callsign' => 'K4CW']);
    makeWfdCabrilloContact($cw, 'CW', ['power_watts' => 10]);
    expect(app(CabrilloExporter::class)->export($cw))->toContain('CATEGORY-POWER: LOW');
});

test('wfd power falls back to configured max when a contact has no power', function () {
    $config = makeWfdCabrilloConfig(['max_power_watts' => 100]);
    makeWfdCabrilloContact($config, 'Phone', ['power_watts' => null]);

    expect(app(CabrilloExporter::class)->export($config))->toContain('CATEGORY-POWER: LOW');
});

test('wfd power category comes from one aggregate query without loading contacts', function () {
    $winterPowerCategory = fn (EventConfiguration $config): string => (fn () => $this->winterPowerCategory($config))
        ->call(app(CabrilloExporter::class));

    $qrp = makeWfdCabrilloConfig(['max_power_watts' => 5]);
    makeWfdCabrilloContact($qrp, 'Phone', ['power_watts' => 10]);
    makeWfdCabrilloContact($qrp, 'CW', ['power_watts' => 5]);
    makeWfdCabrilloContact($qrp, 'CW', ['power_watts' => null]);
    makeWfdCabrilloContact($qrp, 'CW', ['power_watts' => 100, 'is_duplicate' => true]);

    $low = makeWfdCabrilloConfig(['callsign' => 'K4LO', 'max_power_watts' => 5]);
    makeWfdCabrilloContact($low, 'Phone', ['power_watts' => 10]);
    makeWfdCabrilloContact($low, 'Digital', ['power_watts' => 6]);

    $empty = makeWfdCabrilloConfig(['callsign' => 'K4MT', 'max_power_watts' => 100]);

    $hydrated = 0;
    app('events')->listen('eloquent.retrieved: '.Contact::class, function () use (&$hydrated) {
        $hydrated++;
    });

    expect($winterPowerCategory($qrp))->toBe('QRP')
        ->and($winterPowerCategory($low))->toBe('LOW')
        ->and($winterPowerCategory($empty))->toBe('LOW')
        ->and($hydrated)->toBe(0);
});

test('wfd lists distinct operators and omits operators and name when unknown', function () {
    $config = makeWfdCabrilloConfig();
    $alice = User::factory()->create(['call_sign' => 'KY4LV']);
    $bob = User::factory()->create(['call_sign' => 'W4OPS']);

    foreach ([$bob, $alice, $alice] as $user) {
        $session = OperatingSession::factory()->create(['operator_user_id' => $user->id]);
        makeWfdCabrilloContact($config, 'CW', ['operating_session_id' => $session->id]);
    }

    expect(app(CabrilloExporter::class)->export($config))->toContain('OPERATORS: KY4LV, W4OPS');

    $bare = makeWfdCabrilloConfig(['callsign' => 'K4BARE']);
    $bare->createdBy->forceFill(['first_name' => '', 'last_name' => ''])->save();
    $output = app(CabrilloExporter::class)->export($bare);
    expect($output)->not->toContain('OPERATORS:')->not->toContain('NAME:');
});

test('wfd 2027 export leaves out wsjt and satellite contacts', function () {
    $config = makeWfdCabrilloConfig();
    makeWfdCabrilloContact($config, 'Digital', ['callsign' => 'K1GOOD', 'submode' => null]);
    makeWfdCabrilloContact($config, 'Digital', ['callsign' => 'K1FTEIGHT', 'submode' => 'FT8']);
    makeWfdCabrilloContact($config, 'CW', ['callsign' => 'K1SATELL', 'is_satellite' => true]);

    expect(app(CabrilloExporter::class)->export($config))
        ->toContain('K1GOOD')->not->toContain('K1FTEIGHT')->not->toContain('K1SATELL');
});

test('wfd filename is the uppercase callsign', function () {
    $config = makeWfdCabrilloConfig(['callsign' => 'k4sco']);
    expect(app(CabrilloExporter::class)->filename($config))->toBe('K4SCO.log');

    $portable = makeWfdCabrilloConfig(['callsign' => 'k4sco/p']);
    expect(app(CabrilloExporter::class)->filename($portable))->toBe('K4SCO_P.log');
});
