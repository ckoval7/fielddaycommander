<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Select as MarySelect;

/**
 * Mary UI's <x-select> with its label, hint and errors linked to the control.
 */
class Select extends MarySelect
{
    use LabelsFormControl;

    public static function controlNeedle(): string
    {
        return '<select id="{{ $uuid }}"';
    }
}
