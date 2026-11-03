<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\N3fjp\N3fjpContactMapper;
use Database\Seeders\BandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BandSeeder::class);
    $this->mapper = app(N3fjpContactMapper::class);
});

test('maps N3FJP band names to Field Day Commander bands and back', function (string $n3fjp, string $fdc) {
    expect($this->mapper->fdcBandName($n3fjp))->toBe($fdc)
        ->and($this->mapper->n3fjpBandName($fdc))->toBe($n3fjp);
})->with([
    ['40', '40m'],
    ['1.25', '1.25m'],
    ['70', '70cm'],
    ['23', '23cm'],
    ['6cm', '5cm'],
    ['1.25cm', '1.25cm'],
    ['6mm', '6mm'],
    ['SAT', 'Satellite'],
]);

test('reads frequencies in any Windows locale format', function (string $frequency, ?string $band, ?int $hz) {
    expect($this->mapper->frequencyHz($frequency, $band))->toBe($hz);
})->with([
    'en-US' => ['14.07400', '20m', 14_074_000],
    'en-US thousands' => ['1,296.10000', '23cm', 1_296_100_000],
    'de-DE' => ['14,07400', '20m', 14_074_000],
    'de-DE thousands' => ['1.296,10000', '23cm', 1_296_100_000],
    'comma as thousands' => ['1,296', '23cm', 1_296_000_000],
    'fr-FR non-breaking space' => ["1\u{202F}296,10000", '23cm', 1_296_100_000],
    'no band to check' => ['7.125', null, 7_125_000],
    'outside the band' => ['7.125', '20m', null],
    'typed in kHz' => ['14074', '20m', null],
    'blank' => ['', '20m', null],
    'not a number' => ['abc', '20m', null],
]);

test('builds a contact from an N3FJP record', function () {
    $fields = $this->mapper->parseRecord(
        '<FLDCALL>w1aw</FLDCALL><FLDBAND>20</FLDBAND><FLDMODE>SSTV</FLDMODE><FLDMODECONTEST>PH</FLDMODECONTEST>'
        .'<FLDCLASS>2h</FLDCLASS><FLDSECTION>yt</FLDSECTION><FLDDATESTR>2027/01/23</FLDDATESTR>'
        .'<FLDTIMEONSTR>18:04:05</FLDTIMEONSTR><FLDFREQUENCY>14.23000</FLDFREQUENCY><FLDOPERATOR>k3cpk</FLDOPERATOR>'
        .'<FLDSTATION>STATION-2</FLDSTATION><FLDPOWER>100</FLDPOWER><FLDPRIMARYKEY>7</FLDPRIMARYKEY>'
    );

    $dto = $this->mapper->toDto($fields, 'LAPTOP-2', 'n3fjp:abc');

    expect($dto->callsign)->toBe('W1AW')
        ->and($dto->bandName)->toBe('20m')
        ->and($dto->modeName)->toBe('SSTV')
        ->and($dto->parentModeName)->toBeNull()
        ->and($dto->exchangeClass)->toBe('2H')
        ->and($dto->sectionCode)->toBe('YT')
        ->and($dto->timestamp->toIso8601String())->toBe('2027-01-23T18:04:05+00:00')
        ->and($dto->frequencyHz)->toBe(14_230_000)
        ->and($dto->operatorCallsign)->toBe('K3CPK')
        ->and($dto->stationIdentifier)->toBe('STATION-2')
        ->and($dto->adifFields)->toBe(['TX_PWR' => '100'])
        ->and($dto->externalId)->toBe('n3fjp:abc');
});

test('resolves a rig-less phone contact through its parent mode', function () {
    $dto = $this->mapper->toDto(['CALL' => 'W1AW', 'MODE' => 'PH', 'MODECONTEST' => 'PH'], 'LAPTOP-2');

    expect($dto->modeName)->toBe('PH')
        ->and($dto->parentModeName)->toBe('SSB');
});

test('falls back to the sending PC for the station', function () {
    $dto = $this->mapper->toDto(['CALL' => 'W1AW'], 'LAPTOP-2');

    expect($dto->stationIdentifier)->toBe('LAPTOP-2');
});
