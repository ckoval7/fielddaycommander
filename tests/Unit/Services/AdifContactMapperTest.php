<?php

use App\DTOs\ExternalContactDto;
use App\Services\AdifContactMapper;

beforeEach(function () {
    $this->mapper = new AdifContactMapper;
    $this->source = 'wsjtx';
});

test('maps standard ADIF tags to ExternalContactDto', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'BAND' => '20M',
        'MODE' => 'SSB',
        'FREQ' => '14.20000',
        'RST_SENT' => '59',
        'RST_RCVD' => '59',
        'SRX_STRING' => '3A CT',
        'STX_STRING' => '5A NNJ',
        'ARRL_SECT' => 'CT',
        'OPERATOR' => 'K3CPK',
        'STATION_CALLSIGN' => 'K3CPK',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto)->toBeInstanceOf(ExternalContactDto::class)
        ->and($dto->callsign)->toBe('W1AW')
        ->and($dto->timestamp->toDateTimeString())->toBe('2026-04-10 00:55:05')
        ->and($dto->timestamp->timezone->getName())->toBe('UTC')
        ->and($dto->source)->toBe('wsjtx')
        ->and($dto->bandName)->toBe('20M')
        ->and($dto->modeName)->toBe('SSB')
        ->and($dto->frequencyHz)->toBe(14200000)
        ->and($dto->sentReport)->toBe('59')
        ->and($dto->receivedReport)->toBe('59')
        ->and($dto->receivedExchange)->toBe('3A CT')
        ->and($dto->sentExchange)->toBe('5A NNJ')
        ->and($dto->sectionCode)->toBe('CT')
        ->and($dto->operatorCallsign)->toBe('K3CPK')
        ->and($dto->stationIdentifier)->toBe('K3CPK')
        ->and($dto->isDelete)->toBeFalse()
        ->and($dto->isReplace)->toBeFalse()
        ->and($dto->externalId)->toHaveLength(32);
});

test('prefers SUBMODE over MODE for FT4/FST4/Q65', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'MODE' => 'MFSK',
        'SUBMODE' => 'FT4',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->modeName)->toBe('FT4');
});

test('uses MODE when SUBMODE is absent', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'MODE' => 'CW',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->modeName)->toBe('CW');
});

test('converts FREQ MHz to Hz', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'FREQ' => '3.573',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->frequencyHz)->toBe(3573000);
});

test('maps Field Day exchange fields', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'SRX_STRING' => '3A CT',
        'ARRL_SECT' => 'CT',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->receivedExchange)->toBe('3A CT')
        ->and($dto->sectionCode)->toBe('CT');
});

test('falls back to SRX when SRX_STRING is absent', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'SRX' => '42',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->receivedExchange)->toBe('42');
});

test('maps STX_STRING to sentExchange', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'STX_STRING' => '5A NNJ',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->sentExchange)->toBe('5A NNJ');
});

test('falls back to STX when STX_STRING is absent', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'STX' => '7',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->sentExchange)->toBe('7');
});

test('generates deterministic external ID', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'FREQ' => '14.20000',
    ];

    $dto1 = $this->mapper->map($tags, $this->source);
    $dto2 = $this->mapper->map($tags, $this->source);

    expect($dto1->externalId)->toBe($dto2->externalId)
        ->and($dto1->externalId)->toHaveLength(32);
});

test('generates different external IDs for different QSOs', function () {
    $tags1 = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'FREQ' => '14.20000',
    ];

    $tags2 = [
        'CALL' => 'W2WSX',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'FREQ' => '14.20000',
    ];

    $dto1 = $this->mapper->map($tags1, $this->source);
    $dto2 = $this->mapper->map($tags2, $this->source);

    expect($dto1->externalId)->not->toBe($dto2->externalId);
});

test('handles missing optional fields gracefully', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto)->toBeInstanceOf(ExternalContactDto::class)
        ->and($dto->callsign)->toBe('W1AW')
        ->and($dto->bandName)->toBeNull()
        ->and($dto->modeName)->toBeNull()
        ->and($dto->frequencyHz)->toBeNull()
        ->and($dto->sentReport)->toBeNull()
        ->and($dto->receivedReport)->toBeNull()
        ->and($dto->receivedExchange)->toBeNull()
        ->and($dto->sentExchange)->toBeNull()
        ->and($dto->sectionCode)->toBeNull()
        ->and($dto->operatorCallsign)->toBeNull()
        ->and($dto->stationIdentifier)->toBeNull();
});

test('uppercases callsign and operator', function () {
    $tags = [
        'CALL' => 'w1aw',
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
        'OPERATOR' => 'k3cpk',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto->callsign)->toBe('W1AW')
        ->and($dto->operatorCallsign)->toBe('K3CPK');
});

test('returns null when CALL is missing', function () {
    $tags = [
        'QSO_DATE' => '20260410',
        'TIME_ON' => '005505',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto)->toBeNull();
});

test('returns null when QSO_DATE is missing', function () {
    $tags = [
        'CALL' => 'W1AW',
        'TIME_ON' => '005505',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto)->toBeNull();
});

test('returns null when TIME_ON is missing', function () {
    $tags = [
        'CALL' => 'W1AW',
        'QSO_DATE' => '20260410',
    ];

    $dto = $this->mapper->map($tags, $this->source);

    expect($dto)->toBeNull();
});
