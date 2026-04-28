<?php

use App\Support\Version;

test('parses YY.MM.patch with optional v prefix and pre-release suffix', function () {
    expect(Version::parse('26.05.1'))->toBe([
        'year' => 26, 'month' => 5, 'patch' => 1, 'pre' => null,
    ]);

    expect(Version::parse('v26.05.12'))->toBe([
        'year' => 26, 'month' => 5, 'patch' => 12, 'pre' => null,
    ]);

    expect(Version::parse('26.05.0-dev'))->toBe([
        'year' => 26, 'month' => 5, 'patch' => 0, 'pre' => '-dev',
    ]);
});

test('rejects malformed versions and impossible months', function () {
    expect(Version::parse('1.2.3'))->toBeNull();
    expect(Version::parse('26.13.1'))->toBeNull();
    expect(Version::parse('26.00.1'))->toBeNull();
    expect(Version::parse('not a version'))->toBeNull();
});

test('currentMonth uses UTC and YY.MM format', function () {
    $now = new DateTimeImmutable('2026-05-04 03:00:00', new DateTimeZone('UTC'));
    expect(Version::currentMonth($now))->toBe('26.05');
});

test('nextPatch returns patchStart when no prior tags exist for that month', function () {
    expect(Version::nextPatch('26.05', []))->toBe('26.05.1');
    expect(Version::nextPatch('26.05', ['v26.04.7', 'v25.12.3']))->toBe('26.05.1');
});

test('nextPatch increments past the highest existing tag in that month', function () {
    $tags = ['v26.05.1', 'v26.05.2', 'v26.05.10', 'v26.04.99'];
    expect(Version::nextPatch('26.05', $tags))->toBe('26.05.11');
});

test('nextPatch ignores pre-release tags when computing the next patch', function () {
    $tags = ['v26.05.1', 'v26.05.2-rc.1', 'v26.05.0-dev'];
    expect(Version::nextPatch('26.05', $tags))->toBe('26.05.2');
});

test('nextPatch throws on malformed month input', function () {
    Version::nextPatch('2026-05', []);
})->throws(InvalidArgumentException::class);
