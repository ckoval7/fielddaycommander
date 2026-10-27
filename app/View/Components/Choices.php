<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Choices as MaryChoices;

/**
 * Mary UI's <x-choices> with its label, hint and errors linked to the control.
 */
class Choices extends MaryChoices
{
    use LabelsFormControl;

    public static function controlNeedle(): string
    {
        return 'x-ref="searchInput"';
    }
}
