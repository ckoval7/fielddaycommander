<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Support\Facades\Blade;

it('shows pts but reads points', function () {
    expect(Blade::render('<x-points-unit />'))
        ->toContain('<span aria-hidden="true">pts</span><span class="sr-only">points</span>');
});

it('uses the singular for one point', function () {
    expect(Blade::render('<x-points-unit :count="1" />'))
        ->toContain('<span aria-hidden="true">pt</span><span class="sr-only">point</span>');
});

it('leaves no bare pts abbreviation in the views', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());
        // PDF reports are printed, not read by screen readers.
        if (str_starts_with($path, 'reports/') || str_contains($path, '/reports/') || $path === 'components/points-unit.blade.php') {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/(\}\}|\d)\s+pts?(?![\w-])/', $line)) {
                $offenders[] = "{$path}:".($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('renders right after the number', function () {
    expect(Blade::render('20 <x-points-unit />'))
        ->toBe('20 <span aria-hidden="true">pts</span><span class="sr-only">points</span>');
});
