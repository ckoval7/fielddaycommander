<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Finder\Finder;

/**
 * Browsers show confirm() and prompt() differently, and some let people
 * suppress them for good, so wire:confirm and $confirm() open the app's own
 * modal instead (resources/js/components/confirm-dialog.js).
 */
test('both layouts render the shared confirmation modal', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)
        ->toContain('data-confirm-dialog')
        ->toContain('x-data="confirmDialogPanel"')
        ->toContain('aria-labelledby="confirm-dialog-message"');
})->with([
    'app' => 'components.layouts.app',
    'guest' => 'components.layouts.guest',
]);

test('the confirmation modal is a phone-friendly bottom sheet that scrolls inside itself', function () {
    $html = view('components.confirm-dialog')->render();

    expect($html)
        ->toContain('modal-bottom sm:modal-middle')
        ->toContain('max-h-[90dvh] overflow-y-auto overscroll-contain')
        ->toContain('env(safe-area-inset-bottom)');
});

test('views and scripts never call the browser confirm, prompt or alert dialogs', function () {
    $files = Finder::create()
        ->files()
        ->in([resource_path('views'), resource_path('js')])
        ->name(['*.blade.php', '*.js'])
        ->notName('confirm-dialog.js');

    $offenders = [];

    foreach ($files as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $index => $line) {
            if (preg_match('/(^|[^$.\w])(alert|prompt|confirm)\(/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
