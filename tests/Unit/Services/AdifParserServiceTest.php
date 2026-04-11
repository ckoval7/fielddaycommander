<?php

use App\Services\AdifParserService;

beforeEach(function () {
    $this->parser = new AdifParserService;
});

test('detects ADIF version 2 when no version header present', function () {
    $adif = "Some header text\n<EOH>\n <CALL:4>W1AW <BAND:3>20M <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['version'])->toBe(2)
        ->and($result['records'])->toHaveCount(1);
});

test('detects ADIF version 3 from header', function () {
    $adif = "<ADIF_VER:5>3.1.4\n<EOH>\n <CALL:4>W1AW <BAND:3>20M <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['version'])->toBe(3)
        ->and($result['records'])->toHaveCount(1);
});

test('parses tag value pairs correctly', function () {
    $adif = "<EOH>\n <CALL:4>W1AW <BAND:3>20M <MODE:3>SSB <EOR>";

    $result = $this->parser->parse($adif);
    $record = $result['records'][0];

    expect($record['CALL'])->toBe('W1AW')
        ->and($record['BAND'])->toBe('20M')
        ->and($record['MODE'])->toBe('SSB');
});

test('handles tags with type indicator for ADIF 3', function () {
    $adif = "<ADIF_VER:5>3.1.4\n<EOH>\n <CALL:4:S>W1AW <FREQ:8:N>14.20000 <EOR>";

    $result = $this->parser->parse($adif);
    $record = $result['records'][0];

    expect($record['CALL'])->toBe('W1AW')
        ->and($record['FREQ'])->toBe('14.20000');
});

test('parses multiple records', function () {
    $adif = "<EOH>\n <CALL:4>W1AW <BAND:3>20M <EOR>\n <CALL:5>W1QAZ <BAND:3>40M <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['records'])->toHaveCount(2)
        ->and($result['records'][0]['CALL'])->toBe('W1AW')
        ->and($result['records'][1]['CALL'])->toBe('W1QAZ');
});

test('uppercases tag names', function () {
    $adif = "<EOH>\n <call:4>W1AW <band:3>20M <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['records'][0]['CALL'])->toBe('W1AW')
        ->and($result['records'][0]['BAND'])->toBe('20M');
});

test('handles file with no EOH marker', function () {
    $adif = '<CALL:4>W1AW <BAND:3>20M <EOR>';

    $result = $this->parser->parse($adif);

    expect($result['records'])->toHaveCount(1)
        ->and($result['records'][0]['CALL'])->toBe('W1AW');
});

test('skips header content before EOH', function () {
    $adif = "ADIF Export from N1MMLogger.net\nBuilt: 4/7/2026\nK3CPK logs\n<EOH>\n <CALL:4>W1AW <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['records'])->toHaveCount(1)
        ->and($result['records'][0]['CALL'])->toBe('W1AW');
});

test('returns empty records for empty input', function () {
    $result = $this->parser->parse('');

    expect($result['records'])->toBeEmpty()
        ->and($result['errors'])->toContain('ADIF content is empty');
});

test('returns empty records for header-only file', function () {
    $result = $this->parser->parse("Header text\n<EOH>\n");

    expect($result['records'])->toBeEmpty()
        ->and($result['errors'])->toBeEmpty();
});

test('trims whitespace from values', function () {
    $adif = "<EOH>\n <CALL:4>W1AW <ARRL_SECT:3> CT <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['records'][0]['ARRL_SECT'])->toBe('CT');
});

test('parses N1MM sample file format', function () {
    $adif = <<<'N1MM'
ADIF Export from N1MMLogger.net - Version 1.0.11184.0
Built: 4/7/2026 7:24:50 AM
K3CPK logs generated @ 2026-04-11 14:19:07Z
Contest Name: FD - 2026-04-11
<EOH>
 <CALL:4>W1AW <QSO_DATE:8>20260410 <TIME_ON:6>005505 <TIME_OFF:6>005505 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <FREQ:8>14.20000 <CONTEST_ID:14>ARRL-FIELD-DAY <MODE:3>SSB <RST_RCVD:2>59 <RST_SENT:2>59 <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <EOR>
 <CALL:5>W2WSX <QSO_DATE:8>20260410 <TIME_ON:6>005853 <ARRL_SECT:3>NNJ <BAND:3>40M <STATION_CALLSIGN:5>K3CPK <FREQ:7>7.00000 <MODE:4>RTTY <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>2B <EOR>
N1MM;

    $result = $this->parser->parse($adif);

    expect($result['records'])->toHaveCount(2)
        ->and($result['records'][0]['CALL'])->toBe('W1AW')
        ->and($result['records'][0]['QSO_DATE'])->toBe('20260410')
        ->and($result['records'][0]['TIME_ON'])->toBe('005505')
        ->and($result['records'][0]['ARRL_SECT'])->toBe('CT')
        ->and($result['records'][0]['BAND'])->toBe('20M')
        ->and($result['records'][0]['MODE'])->toBe('SSB')
        ->and($result['records'][0]['STATION_CALLSIGN'])->toBe('K3CPK')
        ->and($result['records'][0]['OPERATOR'])->toBe('K3CPK')
        ->and($result['records'][0]['APP_N1MM_EXCHANGE1'])->toBe('3A')
        ->and($result['records'][1]['CALL'])->toBe('W2WSX')
        ->and($result['records'][1]['ARRL_SECT'])->toBe('NNJ')
        ->and($result['records'][1]['MODE'])->toBe('RTTY');
});

test('extracts header metadata', function () {
    $adif = "<PROGRAMID:3>N1M <ADIF_VER:5>3.1.4\n<EOH>\n <CALL:4>W1AW <EOR>";

    $result = $this->parser->parse($adif);

    expect($result['header']['PROGRAMID'])->toBe('N1M')
        ->and($result['header']['ADIF_VER'])->toBe('3.1.4');
});
