<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Range as MaryRange;

/**
 * Mary UI's <x-range> with its label, hint and errors linked to the control.
 */
class Range extends MaryRange
{
    use LabelsFormControl;

    public static function controlNeedle(): string
    {
        return 'type="range"';
    }
}
