<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Textarea as MaryTextarea;

/**
 * Mary UI's <x-textarea> with its label, hint and errors linked to the control.
 */
class Textarea extends MaryTextarea
{
    use LabelsFormControl;

    public static function controlNeedle(): string
    {
        return '<textarea';
    }
}
