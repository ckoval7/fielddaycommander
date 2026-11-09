<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Finder\Finder;

/**
 * Mary's <x-alert> renders only its title and description when it has a
 * title, so body text in the default slot silently disappears. Put the title
 * in the slot (<strong class="block">) instead.
 */
test('no alert has both a title and body text, which Mary would drop', function () {
    $offenders = [];

    $views = Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php');

    foreach ($views as $view) {
        $contents = $view->getContents();

        preg_match_all('/<x-alert\b((?:[^>"]|"[^"]*")*?)(?<!\/)>(.*?)<\/x-alert>/s', $contents, $alerts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($alerts as $alert) {
            [$attributes, $body] = [$alert[1][0], $alert[2][0]];
            $defaultSlot = trim((string) preg_replace('/<x-slot\b.*?<\/x-slot(:[\w-]+)?>/s', '', $body));

            if (preg_match('/(?<![\w:-])title=/', $attributes) === 1 && $defaultSlot !== '') {
                $line = substr_count(substr($contents, 0, $alert[0][1]), "\n") + 1;
                $offenders[] = $view->getRelativePathname().':'.$line;
            }
        }
    }

    expect($offenders)->toBe([]);
});
