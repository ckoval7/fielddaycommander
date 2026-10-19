<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Reports\ReportsIndex;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Models\W1awBulletin;
use App\Services\AdifExporter;
use App\Services\AdifParserService;
use App\Services\WfdEventSummaryService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function makeReportEvent(string $typeCode, string $version, array $overrides = []): EventConfiguration
{
    DB::table('system_config')->insertOrIgnore([
        'key' => 'setup_completed', 'value' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ]);

    test()->seed(EventTypeSeeder::class);
    test()->seed(BonusTypeSeeder::class);

    $type = EventType::where('code', $typeCode)->firstOrFail();
    $class = OperatingClass::create([
        'event_type_id' => $type->id,
        'code' => $typeCode === 'WFD' ? 'O' : 'A',
        'name' => 'Class',
        'allows_gota' => false,
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $type->id,
        'rules_version' => $version,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'callsign' => 'K4SCO/P',
        'club_name' => 'Anytown ARC',
        'section_id' => Section::factory()->create(['code' => 'AL'])->id,
        'operating_class_id' => $class->id,
        'transmitter_count' => 3,
        'max_power_watts' => 100,
        'has_gota_station' => false,
    ], $overrides));
}

function logReportContact(EventConfiguration $config, string $mode, string $band, array $overrides = []): Contact
{
    $modeModel = Mode::firstOrCreate(['name' => $mode], [
        'category' => $mode, 'points_fd' => $mode === 'Phone' ? 1 : 2, 'points_wfd' => $mode === 'Phone' ? 1 : 2,
    ]);
    $bandModel = Band::firstOrCreate(['name' => $band], [
        'meters' => 1, 'frequency_mhz' => 14, 'is_satellite' => $band === 'Satellite',
    ]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'is_gota' => false]);

    return Contact::factory()->create(array_merge([
        'event_configuration_id' => $config->id,
        'band_id' => $bandModel->id,
        'mode_id' => $modeModel->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'is_satellite' => $band === 'Satellite',
        'points' => $config->pointsForContact($modeModel, $station),
        'section_id' => $config->section_id,
        'exchange_class' => '2M',
    ], $overrides));
}

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('view-reports');
});

describe('ADIF export', function () {
    test('maps bands and modes and measures field lengths in bytes', function () {
        $config = makeReportEvent('WFD', '2027');
        logReportContact($config, 'Phone', '20m', ['callsign' => 'W0MET', 'submode' => 'USB', 'qso_time' => '2027-01-23 20:21:20']);
        logReportContact($config, 'Digital', '2200m', ['callsign' => 'W4OPS', 'submode' => 'PSK31', 'qso_time' => '2027-01-23 21:00:00']);
        logReportContact($config, 'CW', '5cm', ['callsign' => 'W4NRR', 'submode' => null, 'qso_time' => '2027-01-24 07:23:00']);

        $adif = app(AdifExporter::class)->export($config);

        expect($adif)
            ->toContain('<ADIF_VER:5>3.1.4')
            ->toContain('<PROGRAMID:12>FD Commander')
            ->toContain('<PROGRAMVERSION:'.strlen(config('app.version')).'>'.config('app.version'))
            ->toContain('<EOH>')
            ->toContain('<CALL:5>W0MET <QSO_DATE:8>20270123 <TIME_ON:6>202120 <BAND:3>20M <MODE:3>SSB <SUBMODE:3>USB <CLASS:2>2M <ARRL_SECT:2>AL <STATION_CALLSIGN:7>K4SCO/P')
            ->toContain('<BAND:5>2190M <MODE:3>PSK <SUBMODE:5>PSK31')
            ->toContain('<BAND:3>6CM <MODE:2>CW <CLASS');

        expect(substr_count($adif, '<EOR>'))->toBe(3)
            ->and(strpos($adif, 'W0MET'))->toBeLessThan(strpos($adif, 'W4OPS'))
            ->and(strpos($adif, 'W4OPS'))->toBeLessThan(strpos($adif, 'W4NRR'));
    });

    test('maps sub-modes to ADIF mode and submode', function (?string $submode, string $category, ?string $mode, ?string $adifSubmode) {
        expect(app(AdifExporter::class)->adifMode($submode, $category))->toBe([$mode, $adifSubmode]);
    })->with([
        'FT8' => ['FT8', 'Digital', 'FT8', null],
        'FT4' => ['FT4', 'Digital', 'MFSK', 'FT4'],
        'JS8' => ['js8', 'Digital', 'MFSK', 'JS8'],
        'LSB' => ['LSB', 'Phone', 'SSB', 'LSB'],
        'FM' => ['FM', 'Phone', 'FM', null],
        'SSTV' => ['SSTV', 'Digital', 'SSTV', null],
        'RTTY' => ['RTTY', 'Digital', 'RTTY', null],
        'unknown' => ['VARA', 'Digital', 'VARA', null],
        'PSK125' => ['PSK125', 'Digital', 'PSK', 'PSK125'],
        'MFSK16' => ['MFSK16', 'Digital', 'MFSK', 'MFSK16'],
        'Olivia' => ['OLIVIA 8/250', 'Digital', 'OLIVIA', 'OLIVIA 8/250'],
        'THOR16' => ['THOR16', 'Digital', 'THOR', 'THOR16'],
        'DominoEX' => ['DOMINOEX', 'Digital', 'DOMINO', 'DOMINOEX'],
        'JT65A' => ['JT65A', 'Digital', 'JT65', 'JT65A'],
        'HELL80' => ['HELL80', 'Digital', 'HELL', 'HELL80'],
        'D-STAR' => ['DSTAR', 'Phone', 'DIGITALVOICE', 'DSTAR'],
        'M17' => ['M17', 'Phone', 'DIGITALVOICE', 'M17'],
        'CW reverse' => ['CW-R', 'CW', 'CW', null],
        'CW default' => [null, 'CW', 'CW', null],
        'Phone default' => [null, 'Phone', 'SSB', null],
        'Digital default' => [null, 'Digital', 'RTTY', null],
    ]);

    test('maps every band name', function (string $name, ?string $adif) {
        expect(app(AdifExporter::class)->adifBand($name))->toBe($adif);
    })->with([
        ['160m', '160M'], ['6m', '6M'], ['1.25m', '1.25M'], ['70cm', '70CM'], ['5cm', '6CM'],
        ['1.25cm', '1.25CM'], ['2.5mm', '2.5MM'], ['2200m', '2190M'], ['630m', '630M'], ['Satellite', null],
    ]);

    test('omits FT8 and satellite contacts under WFD 2027', function () {
        $config = makeReportEvent('WFD', '2027');
        logReportContact($config, 'Phone', '20m', ['callsign' => 'W1AAA', 'submode' => 'USB']);
        logReportContact($config, 'Digital', '20m', ['callsign' => 'W1FT8', 'submode' => 'FT8']);
        logReportContact($config, 'Phone', 'Satellite', ['callsign' => 'W1SAT', 'submode' => 'FM']);
        logReportContact($config, 'Phone', '40m', ['callsign' => 'W1DUP', 'is_duplicate' => true]);

        $adif = app(AdifExporter::class)->export($config);

        expect($adif)->toContain('W1AAA')
            ->not->toContain('W1FT8')
            ->not->toContain('W1SAT')
            ->not->toContain('W1DUP');
    });

    test('includes FT8 and omits BAND for satellite under Field Day', function () {
        $config = makeReportEvent('FD', '2025');
        logReportContact($config, 'Digital', '20m', ['callsign' => 'W1FT8', 'submode' => 'FT8']);
        logReportContact($config, 'Phone', 'Satellite', ['callsign' => 'W1SAT', 'submode' => 'FM']);

        $adif = app(AdifExporter::class)->export($config);

        expect($adif)->toContain('<CALL:5>W1FT8')
            ->toContain('<MODE:3>FT8')
            ->toContain('<CALL:5>W1SAT <QSO_DATE:8>')
            ->not->toMatch('/W1SAT[^<]*(<[A-Z_]+:\d+>[^<]*)*<BAND/');
    });

    test('round-trips through the ADIF parser', function () {
        $config = makeReportEvent('WFD', '2027');
        $operator = User::factory()->create(['call_sign' => 'N0OPR']);
        logReportContact($config, 'Phone', '20m', [
            'callsign' => 'W0MET', 'submode' => 'USB', 'qso_time' => '2027-01-23 20:21:20', 'logger_user_id' => $operator->id,
        ]);
        logReportContact($config, 'Digital', '70cm', [
            'callsign' => 'W4OPS', 'submode' => 'JS8', 'qso_time' => '2027-01-24 09:12:45', 'exchange_class' => '1I',
        ]);

        $parsed = app(AdifParserService::class)->parse(app(AdifExporter::class)->export($config));

        expect($parsed['errors'])->toBe([])
            ->and($parsed['version'])->toBe(3)
            ->and($parsed['header']['PROGRAMID'])->toBe('FD Commander')
            ->and($parsed['records'])->toHaveCount(2)
            ->and($parsed['records'][0])->toMatchArray([
                'CALL' => 'W0MET', 'QSO_DATE' => '20270123', 'TIME_ON' => '202120', 'BAND' => '20M',
                'MODE' => 'SSB', 'SUBMODE' => 'USB', 'CLASS' => '2M', 'ARRL_SECT' => 'AL',
                'STATION_CALLSIGN' => 'K4SCO/P', 'OPERATOR' => 'N0OPR',
            ])
            ->and($parsed['records'][1])->toMatchArray([
                'CALL' => 'W4OPS', 'BAND' => '70CM', 'MODE' => 'MFSK', 'SUBMODE' => 'JS8', 'CLASS' => '1I',
            ]);
    });

    test('filename is the uppercased callsign with slashes replaced', function () {
        $config = makeReportEvent('WFD', '2027');

        expect(app(AdifExporter::class)->filename($config))->toBe('K4SCO_P.adi');
    });

    test('download route requires login and returns the file', function () {
        $config = makeReportEvent('WFD', '2027');
        logReportContact($config, 'CW', '40m', ['callsign' => 'W1CW']);

        $this->get(route('reports.adif'))->assertRedirect(route('login'));

        $response = $this->actingAs($this->user)->get(route('reports.adif'));

        $response->assertSuccessful();
        expect($response->headers->get('Content-Disposition'))->toContain('K4SCO_P.adi')
            ->and($response->streamedContent())->toContain('<CALL:4>W1CW');
    });

    test('download is forbidden without view-reports', function () {
        makeReportEvent('WFD', '2027');

        $this->actingAs(User::factory()->create())->get(route('reports.adif'))->assertForbidden();
    });
});

describe('WFD event summary', function () {
    test('figures equal the ruleset score and implied objectives are checked', function () {
        $config = makeReportEvent('WFD', '2027');
        logReportContact($config, 'Phone', '20m', ['submode' => 'USB']);
        logReportContact($config, 'Phone', '40m');
        logReportContact($config, 'CW', '40m');
        logReportContact($config, 'Digital', '20m', ['submode' => 'PSK31']);
        logReportContact($config, 'Digital', '20m', ['submode' => 'FT8']);
        logReportContact($config, 'Phone', 'Satellite');

        $full = BonusType::where('rules_version', '2027')->where('code', 'alt_power_full')->firstOrFail();
        EventBonus::create([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $full->id,
            'quantity' => 1,
            'calculated_points' => 0,
            'is_verified' => true,
        ]);

        $data = app(WfdEventSummaryService::class)->getData($config->fresh());
        $score = $config->fresh()->scoreBreakdown();
        $byCode = collect($data['objectives'])->keyBy('code');

        expect($data['callsign'])->toBe('K4SCO/P')
            ->and($data['location'])->toBe('AL')
            ->and($data['entry_class'])->toBe('3O')
            ->and($data['objectives'])->toHaveCount(13)
            ->and($byCode['alt_power_full']['achieved'])->toBeTrue()
            ->and($byCode['alt_power_equipment']['achieved'])->toBeTrue()
            ->and($byCode['qrp']['achieved'])->toBeFalse()
            ->and($data['phone_contacts'])->toBe(2)
            ->and($data['phone_points'])->toBe(2)
            ->and($data['cw_digital_contacts'])->toBe(2)
            ->and($data['cw_digital_points'])->toBe(4)
            ->and($data['qso_points'])->toBe($score->value('qso_points'))
            ->and($data['phone_points'] + $data['cw_digital_points'])->toBe($data['qso_points'])
            ->and($data['objective_multiplier'])->toBe($score->value('objective_multiplier'))
            ->and($data['objective_multiplier'])->toBe(5)
            ->and($data['total_multiplier'])->toBe(6)
            ->and($data['final_points'])->toBe($score->total)
            ->and($data['final_points'])->toBe($data['qso_points'] * ($data['objective_multiplier'] + 1))
            ->and($data['no_credit_contacts'])->toBe(2);
    });

    test('route serves a PDF for WFD 2027 and 2026', function (string $version) {
        $config = makeReportEvent('WFD', $version);
        logReportContact($config, 'CW', '40m');

        $this->get(route('reports.wfd-event-summary'))->assertRedirect(route('login'));

        $response = $this->actingAs($this->user)->get(route('reports.wfd-event-summary'));

        $response->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
        expect($response->headers->get('Content-Disposition'))->toContain('k4sco_p-')->toContain('event-summary.pdf');
    })->with(['2027', '2026']);

    test('includes the saved bulletin copy under its WFD name', function () {
        $config = makeReportEvent('WFD', '2027');
        W1awBulletin::factory()->create([
            'event_configuration_id' => $config->id,
            'bulletin_text' => 'WINTER FIELD DAY BULLETIN TEXT',
        ]);

        $data = app(WfdEventSummaryService::class)->getData($config->fresh());

        expect($data['bulletin']['name'])->toBe('WFD Bulletin')
            ->and($data['bulletin']['text'])->toBe('WINTER FIELD DAY BULLETIN TEXT')
            ->and(collect($data['objectives'])->firstWhere('code', 'bulletin')['achieved'])->toBeTrue();

        $this->actingAs($this->user)->get(route('reports.wfd-event-summary'))->assertSuccessful();
    });

    test('route is not available for Field Day events', function () {
        makeReportEvent('FD', '2025');

        $this->actingAs($this->user)->get(route('reports.wfd-event-summary'))->assertNotFound();
    });

    test('reports page offers the summary only for WFD events', function () {
        makeReportEvent('WFD', '2027');

        Livewire\Livewire::actingAs($this->user)->test(ReportsIndex::class)
            ->assertSee('WFD Event Summary (PDF)')
            ->assertSee('ADIF Log')
            ->assertDontSee('Submission Sheet');
    });

    test('reports page hides the summary for Field Day events', function () {
        makeReportEvent('FD', '2025');

        Livewire\Livewire::actingAs($this->user)->test(ReportsIndex::class)
            ->assertDontSee('WFD Event Summary (PDF)')
            ->assertSee('ADIF Log')
            ->assertSee('Submission Sheet');
    });
});
