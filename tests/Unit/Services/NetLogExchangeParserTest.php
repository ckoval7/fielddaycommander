<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NetPriority;
use App\Services\NetLogExchangeParser;

beforeEach(function () {
    $this->parser = new NetLogExchangeParser;
});

test('splits calling station, your call and message', function () {
    expect($this->parser->parse('  aid7   ncs  water low  '))->toBe([
        'from' => 'AID7',
        'to' => 'NCS',
        'message' => 'water low',
        'priority' => null,
    ]);
});

test('matches known tactical names first, longest first, keeping their spelling', function () {
    $parsed = $this->parser->parse('aid 12 net control runner down', ['Aid 1', 'Aid 12', 'Net Control']);

    expect($parsed['from'])->toBe('Aid 12')
        ->and($parsed['to'])->toBe('Net Control')
        ->and($parsed['message'])->toBe('runner down');
});

test('a known name must end at a word boundary', function () {
    $parsed = $this->parser->parse('aid 70 ncs check in', ['Aid 7']);

    expect($parsed['from'])->toBe('AID')
        ->and($parsed['to'])->toBe('70');
});

test('the message is optional', function () {
    expect($this->parser->parse('AID7 NCS')['message'])->toBe('');
});

test('a leading EMERGENCY or PRIORITY sets the priority', function () {
    expect($this->parser->parse('AID7 NCS Emergency runner down'))
        ->priority->toBe(NetPriority::Emergency)
        ->message->toBe('runner down')
        ->and($this->parser->parse('AID7 NCS priority need ice')['priority'])->toBe(NetPriority::Priority)
        ->and($this->parser->parse('AID7 NCS priorityless')['priority'])->toBeNull();
});

test('a line needs both stations', function (string $line) {
    $this->parser->parse($line);
})->with(['', 'AID7'])->throws(InvalidArgumentException::class);
