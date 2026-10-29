<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\Phonetics;

it('spells callsigns and exchanges in the NATO alphabet', function () {
    expect(Phonetics::spell('w1aw'))->toBe('Whiskey One Alpha Whiskey')
        ->and(Phonetics::spell('3A'))->toBe('Three Alpha');
});

it('leaves characters outside the alphabet as they are', function () {
    expect(Phonetics::spell('K1/P'))->toBe('Kilo One / Papa')
        ->and(Phonetics::spell(''))->toBe('');
});
