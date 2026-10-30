<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

test('spoken shows one text and reads another', function () {
    expect((string) spoken('N/A', 'not available'))
        ->toBe('<span aria-hidden="true">N/A</span><span class="sr-only">not available</span>');
});

test('spoken escapes both texts', function () {
    expect((string) spoken('<b>', '<i>'))
        ->toBe('<span aria-hidden="true">&lt;b&gt;</span><span class="sr-only">&lt;i&gt;</span>');
});

test('spokenDuration spells out units', function (string $short, string $long) {
    expect(spokenDuration($short))->toBe($long);
})->with([
    ['5m ago', '5 minutes ago'],
    ['1h ago', '1 hour ago'],
    ['2d ago', '2 days ago'],
    ['17h 57m', '17 hours 57 minutes'],
    ['1d 2h 3m', '1 day 2 hours 3 minutes'],
    ['4m 1s', '4 minutes 1 second'],
    ['just now', 'just now'],
]);

test('spokenWatts reads the unit', function () {
    expect((string) spokenWatts(100))->toBe('<span aria-hidden="true">100W</span><span class="sr-only">100 watts</span>')
        ->and((string) spokenWatts(1))->toContain('1 watt<');
});

test('spokenAbbreviations expands scoring and dashboard short forms', function (string $short, string $long) {
    expect(spokenAbbreviations($short))->toBe($long);
})->with([
    ['QSO Base Pts', 'QSO Base points'],
    ['Power Multi.', 'Power multiplier'],
    ['Bonus Pts', 'Bonus points'],
    ['12.5/hr', '12.5 per hour'],
    ['45 Qs', '45 contacts'],
    ['OM + 1', 'OM + 1'],
]);

test('spokenCallsigns spells callsigns phonetically', function (string $text, string $said) {
    expect(spokenCallsigns($text))->toBe($said);
})->with([
    ['W1AW Bulletin', 'Whiskey One Alpha Whiskey Bulletin'],
    ['K6KPH', 'Kilo Six Kilo Papa Hotel'],
    ['Copied from VE3XYZ', 'Copied from Victor Echo Three X-ray Yankee Zulu'],
    ['3A CT', '3A CT'],
    ['FT8 on 20m', 'FT8 on 20m'],
    ['w1aw', 'w1aw'],
]);

test('spokenAbbreviations spells callsigns and WFD', function () {
    expect(spokenAbbreviations('WFD Bulletin'))->toBe('W F D Bulletin')
        ->and(spokenAbbreviations('e.g. W1AW 3A CT'))->toBe('e.g. Whiskey One Alpha Whiskey 3A CT');
});

test('spokenPlaceholder is null when screen readers already read it right', function () {
    expect(spokenPlaceholder('For example, W1AW'))->toBe('For example, Whiskey One Alpha Whiskey')
        ->and(spokenPlaceholder('Last name'))->toBeNull()
        ->and(spokenPlaceholder(null))->toBeNull();
});
