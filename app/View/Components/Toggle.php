<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Toggle as MaryToggle;

/**
 * Mary UI's <x-toggle> with its label, hint and errors linked to the control.
 */
class Toggle extends MaryToggle
{
    use LabelsFormControl;

    public static function controlNeedle(): string
    {
        return 'id="{{ $uuid }}"';
    }

    public static function labelledByLegend(): bool
    {
        return false;
    }

    public static function appendsModelToId(): bool
    {
        return true;
    }
}
