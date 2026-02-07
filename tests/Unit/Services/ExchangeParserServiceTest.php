<?php

use App\Models\Section;
use App\Services\ExchangeParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test sections
    Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
    Section::create(['code' => 'STX', 'name' => 'South Texas', 'region' => 'W5', 'is_active' => true]);
    Section::create(['code' => 'WWA', 'name' => 'Western Washington', 'region' => 'W7', 'is_active' => true]);
    Section::create(['code' => 'DX', 'name' => 'DX', 'region' => 'DX', 'is_active' => true]);

    $this->parser = new ExchangeParserService;
});

test('parses a valid exchange', function () {
    $result = $this->parser->parse('W1AW 3A CT');

    expect($result['success'])->toBeTrue()
        ->and($result['callsign'])->toBe('W1AW')
        ->and($result['transmitter_count'])->toBe(3)
        ->and($result['class_code'])->toBe('A')
        ->and($result['section_code'])->toBe('CT')
        ->and($result['section_id'])->not->toBeNull()
        ->and($result['errors'])->toBeEmpty();
});

test('parses lowercase input', function () {
    $result = $this->parser->parse('w1aw 3a ct');

    expect($result['success'])->toBeTrue()
        ->and($result['callsign'])->toBe('W1AW')
        ->and($result['section_code'])->toBe('CT');
});

test('handles extra whitespace', function () {
    $result = $this->parser->parse('  W1AW   3A   CT  ');

    expect($result['success'])->toBeTrue()
        ->and($result['callsign'])->toBe('W1AW');
});

test('rejects empty input', function () {
    $result = $this->parser->parse('');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->toContain('Exchange is empty');
});

test('rejects input with only one part', function () {
    $result = $this->parser->parse('W1AW');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('callsign, class, and section');
});

test('rejects input with only two parts', function () {
    $result = $this->parser->parse('W1AW 3A');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('callsign, class, and section');
});

test('rejects input with too many parts', function () {
    $result = $this->parser->parse('W1AW 3A CT EXTRA');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->toContain('Too many parts in exchange');
});

test('rejects invalid callsign too short', function () {
    $result = $this->parser->parse('AB 3A CT');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Invalid callsign');
});

test('rejects callsign without digits', function () {
    $result = $this->parser->parse('ABCDEF 3A CT');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Invalid callsign');
});

test('rejects callsign without letters', function () {
    $result = $this->parser->parse('12345 3A CT');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Invalid callsign');
});

test('parses all FD class codes A through F', function (string $classCode) {
    $result = $this->parser->parse("W1AW 3{$classCode} CT");

    expect($result['success'])->toBeTrue()
        ->and($result['class_code'])->toBe($classCode);
})->with(['A', 'B', 'C', 'D', 'E', 'F']);

test('rejects invalid class code', function () {
    $result = $this->parser->parse('W1AW 3Z CT');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Invalid class');
});

test('parses double-digit transmitter count', function () {
    $result = $this->parser->parse('W1AW 15A CT');

    expect($result['success'])->toBeTrue()
        ->and($result['transmitter_count'])->toBe(15);
});

test('rejects unknown section', function () {
    $result = $this->parser->parse('W1AW 3A ZZZ');

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Unknown section');
});

test('parses multi-letter section code', function () {
    $result = $this->parser->parse('W1AW 3A STX');

    expect($result['success'])->toBeTrue()
        ->and($result['section_code'])->toBe('STX');
});

test('parses callsign with slash', function () {
    $result = $this->parser->parse('VE3/W1AW 3A CT');

    expect($result['success'])->toBeTrue()
        ->and($result['callsign'])->toBe('VE3/W1AW');
});

test('extractCallsign returns callsign from partial input', function () {
    $callsign = $this->parser->extractCallsign('W1AW');

    expect($callsign)->toBe('W1AW');
});

test('extractCallsign returns callsign with trailing text', function () {
    $callsign = $this->parser->extractCallsign('W1AW 3A');

    expect($callsign)->toBe('W1AW');
});

test('extractCallsign returns null for empty input', function () {
    $callsign = $this->parser->extractCallsign('');

    expect($callsign)->toBeNull();
});

test('extractCallsign returns null for invalid callsign', function () {
    $callsign = $this->parser->extractCallsign('ABC');

    expect($callsign)->toBeNull();
});
