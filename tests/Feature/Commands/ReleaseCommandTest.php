<?php

test('app:release dry-run prints current and next without writing', function () {
    $versionPath = base_path('VERSION');
    $original = file_exists($versionPath) ? file_get_contents($versionPath) : null;

    try {
        file_put_contents($versionPath, "26.05.0-dev\n");

        $this->artisan('app:release', ['--month' => '26.05'])
            ->expectsOutputToContain('Current VERSION : 26.05.0-dev')
            ->expectsOutputToContain('Next release    : 26.05.')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        expect(trim((string) file_get_contents($versionPath)))->toBe('26.05.0-dev');
    } finally {
        if ($original !== null) {
            file_put_contents($versionPath, $original);
        }
    }
});

test('app:release --write updates the VERSION file', function () {
    $versionPath = base_path('VERSION');
    $original = file_exists($versionPath) ? file_get_contents($versionPath) : null;

    try {
        file_put_contents($versionPath, "26.05.0-dev\n");

        $this->artisan('app:release', ['--month' => '26.05', '--write' => true])
            ->assertSuccessful();

        expect(trim((string) file_get_contents($versionPath)))
            ->toMatch('/^26\.05\.\d+$/');
    } finally {
        if ($original !== null) {
            file_put_contents($versionPath, $original);
        }
    }
});

test('app:release fails on a malformed --month value', function () {
    $this->artisan('app:release', ['--month' => '2026-05'])
        ->assertFailed();
});
