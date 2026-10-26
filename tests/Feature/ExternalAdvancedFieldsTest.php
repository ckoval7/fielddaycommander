<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
use App\Services\AdifContactMapper;
use App\Services\AdifExporter;
use App\Services\AdifImportService;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\N1mmPacketParser;
use App\Support\AdvancedContactFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 6]);
    $this->mode = Mode::firstOrCreate(['name' => 'Digital'], ['category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->qsoTime = now()->addDays(30)->setTime(19, 0, 0);
    $this->config = EventConfiguration::factory()->create(['callsign' => 'W2XYZ']);
    $this->config->event->update([
        'start_time' => $this->qsoTime->copy()->subDay(),
        'end_time' => $this->qsoTime->copy()->addDay(),
    ]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'DIGI-PC']);
    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);
});

/**
 * One ADIF record the way WSJT-X logs it, plus tags the app has no column for.
 */
function wsjtxRecord(Carbon $qsoTime): string
{
    $tags = [
        'CALL' => 'W1AW',
        'GRIDSQUARE' => 'FN31pr',
        'MODE' => 'MFSK',
        'SUBMODE' => 'FT4',
        'RST_SENT' => '-10',
        'RST_RCVD' => '+05',
        'QSO_DATE' => $qsoTime->format('Ymd'),
        'TIME_ON' => $qsoTime->format('His'),
        'QSO_DATE_OFF' => $qsoTime->format('Ymd'),
        'TIME_OFF' => $qsoTime->format('His'),
        'BAND' => '20m',
        'FREQ' => '14.080500',
        'STATION_CALLSIGN' => 'W2XYZ',
        'MY_GRIDSQUARE' => 'FN20',
        'TX_PWR' => '50',
        'NAME' => 'Hiram',
        'COMMENT' => 'Field Day test',
        'OPERATOR' => 'K3CPK',
        'CLASS' => '3A',
        'ARRL_SECT' => 'CT',
        'APP_WSJTX_FLAG' => 'Y',
    ];

    $record = '';
    foreach ($tags as $name => $value) {
        $record .= '<'.$name.':'.strlen($value).'>'.$value.' ';
    }

    return $record.'<EOR>';
}

/**
 * Tags of the first record in an ADIF document.
 *
 * @return array<string, string>
 */
function firstAdifRecord(string $adif): array
{
    return app(AdifParserService::class)->parse($adif)['records'][0];
}

describe('reading ADIF', function () {
    test('splits a record into advanced fields and the tags to keep', function () {
        $result = AdvancedContactFields::fromAdif([
            'CALL' => 'W1AW',
            'FREQ' => '14.074',
            'RST_SENT' => '599',
            'RST_RCVD' => '5NN',
            'GRIDSQUARE' => 'fn31PR45',
            'NAME' => 'Bob',
            'COMMENT' => 'Hello',
            'MY_GRIDSQUARE' => 'FN20',
            'TX_PWR' => '',
        ]);

        expect($result['fields'])->toBe([
            'frequency_hz' => 14_074_000,
            'rst_sent' => '599',
            'grid_square' => 'FN31pr45',
            'contact_name' => 'Bob',
            'notes' => 'Hello',
        ])->and($result['extra'])->toBe([
            'RST_RCVD' => '5NN',
            'MY_GRIDSQUARE' => 'FN20',
        ]);
    });

    test('keeps a value too long for its column with the other tags', function () {
        $longNote = str_repeat('x', AdvancedContactFields::NOTES_MAX + 1);

        $result = AdvancedContactFields::fromAdif(['COMMENT' => $longNote]);

        expect($result['fields'])->toBe([])
            ->and($result['extra'])->toBe(['COMMENT' => $longNote]);
    });

    test('the UDP ADIF mapper carries the advanced fields and other tags', function () {
        $dto = app(AdifContactMapper::class)->map(firstAdifRecord(wsjtxRecord($this->qsoTime)), 'wsjtx');

        expect($dto->advancedFields)->toBe([
            'grid_square' => 'FN31pr',
            'rst_sent' => '-10',
            'rst_received' => '+05',
            'frequency_hz' => 14_080_500,
            'contact_name' => 'Hiram',
            'notes' => 'Field Day test',
        ])->and($dto->adifFields)->toMatchArray([
            'MY_GRIDSQUARE' => 'FN20',
            'TX_PWR' => '50',
            'APP_WSJTX_FLAG' => 'Y',
            'QSO_DATE_OFF' => $this->qsoTime->format('Ymd'),
        ])->and($dto->adifFields)->not->toHaveKeys(['CALL', 'BAND', 'MODE', 'FREQ']);
    });

    test('N1MM contacts carry reports, frequency, grid, name and comment', function () {
        $dto = app(N1mmPacketParser::class)->parse('<?xml version="1.0" encoding="utf-8"?>
            <contactinfo>
                <timestamp>2026-06-28 18:43:38</timestamp>
                <band>14</band>
                <rxfreq>1403250</rxfreq>
                <mode>CW</mode>
                <call>W1AW</call>
                <snt>599</snt>
                <rcv>579</rcv>
                <gridsquare>FN31</gridsquare>
                <name>Hiram</name>
                <comment>Loud</comment>
                <StationName>CONTEST-PC</StationName>
                <ID>abc</ID>
            </contactinfo>');

        expect($dto->advancedFields)->toBe([
            'frequency_hz' => 14_032_500,
            'rst_sent' => '599',
            'rst_received' => '579',
            'grid_square' => 'FN31',
            'contact_name' => 'Hiram',
            'notes' => 'Loud',
        ]);
    });
});

describe('external loggers', function () {
    test('a logged contact stores the advanced fields and other tags', function () {
        $dto = app(AdifContactMapper::class)->map(firstAdifRecord(wsjtxRecord($this->qsoTime)), 'wsjtx');

        $contact = app(ExternalContactHandler::class)->handleContact($dto, $this->config)->fresh();

        expect($contact)
            ->frequency_hz->toBe(14_080_500)
            ->rst_sent->toBe('-10')
            ->rst_received->toBe('+05')
            ->grid_square->toBe('FN31pr')
            ->contact_name->toBe('Hiram')
            ->notes->toBe('Field Day test')
            ->and($contact->adif_fields)->toMatchArray(['MY_GRIDSQUARE' => 'FN20', 'APP_WSJTX_FLAG' => 'Y']);
    });

    test('everything a logger sent over ADIF comes back out in the export', function () {
        $original = firstAdifRecord(wsjtxRecord($this->qsoTime));
        $dto = app(AdifContactMapper::class)->map($original, 'wsjtx');
        app(ExternalContactHandler::class)->handleContact($dto, $this->config);

        $exported = firstAdifRecord(app(AdifExporter::class)->export($this->config->fresh()));

        // Derived tags are rebuilt from the contact: same values, ADIF's spelling.
        expect($exported)->toMatchArray([
            ...collect($original)->except(['BAND', 'FREQ', 'STATION_CALLSIGN'])->all(),
            'BAND' => '20M',
            'FREQ' => '14.0805',
            'STATION_CALLSIGN' => 'W2XYZ',
        ]);
    });
});

describe('ADIF file import', function () {
    beforeEach(function () {
        $this->import = AdifImport::factory()->create([
            'event_configuration_id' => $this->config->id,
            'user_id' => $this->user->id,
        ]);
    });

    test('an imported contact keeps every tag of its record through export', function () {
        $original = firstAdifRecord(wsjtxRecord($this->qsoTime));

        AdifImportRecord::factory()->create([
            'adif_import_id' => $this->import->id,
            'raw_data' => $original,
            'callsign' => 'W1AW',
            'qso_time' => $this->qsoTime,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'mode_name' => 'FT4',
            'section_id' => $this->section->id,
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'exchange_class' => '3A',
            'status' => AdifRecordStatus::Ready,
        ]);

        (new AdifImportService)->import($this->import);

        $contact = Contact::sole();
        expect($contact)
            ->frequency_hz->toBe(14_080_500)
            ->rst_received->toBe('+05')
            ->grid_square->toBe('FN31pr');

        $exported = firstAdifRecord(app(AdifExporter::class)->export($this->config->fresh()));

        expect($exported)->toMatchArray([
            ...collect($original)->except(['BAND', 'FREQ', 'STATION_CALLSIGN', 'OPERATOR'])->all(),
            'BAND' => '20M',
            'FREQ' => '14.0805',
        ]);
    });

    test('merging a matched record fills only the fields the contact lacks', function () {
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
            'qso_time' => $this->qsoTime,
            'rst_sent' => '-12',
        ]);

        AdifImportRecord::factory()->create([
            'adif_import_id' => $this->import->id,
            'raw_data' => firstAdifRecord(wsjtxRecord($this->qsoTime)),
            'callsign' => 'W1AW',
            'qso_time' => $this->qsoTime,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'matched_contact_id' => $contact->id,
            'status' => AdifRecordStatus::DuplicateMatch,
        ]);

        (new AdifImportService)->import($this->import);

        expect($contact->fresh())
            ->rst_sent->toBe('-12')
            ->rst_received->toBe('+05')
            ->frequency_hz->toBe(14_080_500)
            ->adif_fields->toMatchArray(['MY_GRIDSQUARE' => 'FN20']);
    });
});

describe('ADIF export', function () {
    test('values held in the app win over stored copies of the same tag', function () {
        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $session->id,
            'callsign' => 'W1AW',
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'qso_time' => $this->qsoTime,
            'is_duplicate' => false,
            'rst_received' => '599',
            'adif_fields' => [
                'RST_RCVD' => '5NN',
                'CALL' => 'BOGUS',
                'BAD<TAG' => 'x',
                'MY_GRIDSQUARE' => 'FN20',
            ],
        ]);

        $exported = firstAdifRecord(app(AdifExporter::class)->export($this->config->fresh()));

        expect($exported)
            ->toMatchArray(['CALL' => 'W1AW', 'RST_RCVD' => '599', 'MY_GRIDSQUARE' => 'FN20'])
            ->not->toHaveKey('BAD<TAG');
    });
});
