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
    use LabelsFormControl {
        templateReplacements as formControlReplacements;
    }

    public const OPTION = '<option value="{{ data_get($option, $optionValue) }}" @if(data_get($option, \'disabled\')) disabled @endif>';

    public static function controlNeedle(): string
    {
        return '<select id="{{ $uuid }}"';
    }

    /**
     * An option can carry an `aria-label` for screen readers, such as a
     * callsign spelled phonetically.
     *
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return self::formControlReplacements() + [
            self::OPTION => '<option value="{{ data_get($option, $optionValue) }}" @if(data_get($option, \'disabled\')) disabled @endif @if(data_get($option, \'aria-label\')) aria-label="{{ data_get($option, \'aria-label\') }}" @endif>',
        ];
    }
}
