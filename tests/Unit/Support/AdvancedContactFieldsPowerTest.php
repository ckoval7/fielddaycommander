<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\AdvancedContactFields;

test('TX_PWR is read as whole watts, rounded up', function (array $tags, ?int $expected) {
    expect(AdvancedContactFields::powerWattsFromAdif($tags))->toBe($expected);
})->with([
    'whole watts' => [['TX_PWR' => '100'], 100],
    'fractional watts' => [['TX_PWR' => '4.2'], 5],
    'trailing W' => [['TX_PWR' => '400 W'], 400],
    'lowercase tag' => [['tx_pwr' => '50'], 50],
    'missing' => [['CALL' => 'W1AW'], null],
    'blank' => [['TX_PWR' => ''], null],
    'zero' => [['TX_PWR' => '0'], null],
    'not a number' => [['TX_PWR' => 'QRO'], null],
    'absurd' => [['TX_PWR' => '5000000'], null],
]);
