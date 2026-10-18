<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Console\Commands\Release;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

/**
 * A Release command that records the git commands it would run instead of
 * shelling out, so the --tag / --push option chain can be exercised without
 * touching the repository.
 */
function fakeReleaseCommand(): Release
{
    return new class extends Release
    {
        /** @var array<int, array<int, string>> */
        public array $gitCalls = [];

        /**
         * @return array<int, string>
         */
        protected function existingTags(): array
        {
            return ['v26.05.3'];
        }

        /**
         * @param  array<int, string>  $args
         */
        protected function git(array $args): bool
        {
            $this->gitCalls[] = $args;

            return true;
        }
    };
}

/**
 * Run a Release command instance with the given options against a temporary
 * VERSION file, restoring the original afterwards.
 *
 * @param  array<string, mixed>  $options
 */
function runRelease(Release $command, array $options): string
{
    $versionPath = base_path('VERSION');
    $original = file_exists($versionPath) ? file_get_contents($versionPath) : null;

    try {
        file_put_contents($versionPath, "26.05.0-dev\n");

        $command->setLaravel(app());
        $command->run(
            new ArrayInput($options, $command->getDefinition()),
            new BufferedOutput,
        );

        return trim((string) file_get_contents($versionPath));
    } finally {
        if ($original !== null) {
            file_put_contents($versionPath, $original);
        }
    }
}

test('app:release --tag implies --write and cuts an annotated tag', function () {
    $command = fakeReleaseCommand();

    $version = runRelease($command, ['--month' => '26.05', '--tag' => true]);

    expect($version)->toBe('26.05.4');
    expect($command->gitCalls)->toBe([
        ['commit', '-am', 'chore(release): v26.05.4'],
        ['tag', '-a', 'v26.05.4', '-m', 'Release v26.05.4'],
    ]);
});

test('app:release --push implies --tag and pushes the branch and tag', function () {
    $command = fakeReleaseCommand();

    $version = runRelease($command, ['--month' => '26.05', '--push' => true]);

    expect($version)->toBe('26.05.4');
    expect($command->gitCalls)->toBe([
        ['commit', '-am', 'chore(release): v26.05.4'],
        ['tag', '-a', 'v26.05.4', '-m', 'Release v26.05.4'],
        ['push'],
        ['push', 'origin', 'v26.05.4'],
    ]);
});
