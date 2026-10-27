<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Support\Facades\Blade;

it('reads numbered rule sections with "point" instead of a dot', function () {
    $html = Blade::render('<x-bonus-rule-help :rule="$rule" rulebook="ARRL">Bonus</x-bonus-rule-help>', [
        'rule' => ['section' => '7.3.1', 'text' => 'Emergency power.'],
    ]);

    expect($html)
        ->toContain('aria-label="Toggle ARRL rule 7 point 3 point 1 details"')
        ->toContain('<span aria-hidden="true">7.3.1</span><span class="sr-only">7 point 3 point 1</span>');
});

it('leaves named rule sections as they are', function () {
    $html = Blade::render('<x-bonus-rule-help :rule="$rule" rulebook="WFDA">Bonus</x-bonus-rule-help>', [
        'rule' => ['section' => 'Objective & Multipliers', 'text' => 'Operate outdoors.'],
    ]);

    expect($html)
        ->toContain('WFDA Rule Objective &amp; Multipliers:')
        ->not->toContain('sr-only');
});
