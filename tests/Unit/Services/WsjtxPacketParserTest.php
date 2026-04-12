<?php

use App\Services\WsjtxPacketParser;

function buildWsjtxPacket(int $type, string $id, string $payload = ''): string
{
    $magic = pack('N', 0xADBCCBDA);
    $schema = pack('N', 3);
    $msgType = pack('N', $type);
    $idBytes = packUtf8($id);

    return $magic.$schema.$msgType.$idBytes.$payload;
}

function packUtf8(string $value): string
{
    return pack('N', strlen($value)).$value;
}

function packNullUtf8(): string
{
    return pack('N', 0xFFFFFFFF);
}

beforeEach(function () {
    $this->parser = new WsjtxPacketParser;
});

test('parses Logged ADIF message (type 12)', function () {
    $adifText = '<call:4>W1AW <band:3>20m <mode:2>CW <eor>';
    $packet = buildWsjtxPacket(12, 'WSJT-X', packUtf8($adifText));

    $result = $this->parser->parse($packet);

    expect($result)->toBe($adifText);
});

test('parses Heartbeat message (type 0)', function () {
    $maxSchema = pack('N', 3);
    $version = packUtf8('2.6.1');
    $revision = packUtf8('abc123');
    $packet = buildWsjtxPacket(0, 'WSJT-X', $maxSchema.$version.$revision);

    $result = $this->parser->parse($packet);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe('WSJT-X')
        ->and($result['max_schema'])->toBe(3)
        ->and($result['version'])->toBe('2.6.1')
        ->and($result['revision'])->toBe('abc123');
});

test('returns null for invalid magic number', function () {
    $packet = pack('N', 0xDEADBEEF).pack('N', 3).pack('N', 0).packUtf8('WSJT-X');

    $result = $this->parser->parse($packet);

    expect($result)->toBeNull();
});

test('returns null for unknown message type', function () {
    $packet = buildWsjtxPacket(99, 'WSJT-X');

    $result = $this->parser->parse($packet);

    expect($result)->toBeNull();
});

test('returns null for ignored message types', function () {
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13] as $type) {
        $packet = buildWsjtxPacket($type, 'WSJT-X');
        expect($this->parser->parse($packet))->toBeNull("Expected null for message type {$type}");
    }
});

test('returns null for truncated packet', function () {
    $packet = pack('N', 0xADBCCBDA).pack('N', 3);

    $result = $this->parser->parse($packet);

    expect($result)->toBeNull();
});

test('returns null for empty input', function () {
    $result = $this->parser->parse('');

    expect($result)->toBeNull();
});

test('handles heartbeat without optional fields (schema 2)', function () {
    $magic = pack('N', 0xADBCCBDA);
    $schema = pack('N', 2);
    $msgType = pack('N', 0);
    $id = packUtf8('WSJT-X');
    $packet = $magic.$schema.$msgType.$id;

    $result = $this->parser->parse($packet);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe('WSJT-X')
        ->and($result['max_schema'])->toBe(2)
        ->and($result['version'])->toBeNull()
        ->and($result['revision'])->toBeNull();
});
