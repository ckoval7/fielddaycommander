<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\Input as MaryInput;

/**
 * Mary UI's <x-input> with its label, hint and errors linked to the control,
 * and a `clearable` icon that is a named, keyboard-reachable button.
 */
class Input extends MaryInput
{
    use LabelsFormControl {
        templateReplacements as formControlReplacements;
    }

    public const CLEAR_ICON = '<x-mary-icon x-on:click="$wire.set(\'{{ $modelName() }}\', \'\', {{ json_encode($attributes->wire(\'model\')->hasModifier(\'live\')) }})"  name="o-x-mark" class="cursor-pointer w-4 h-4 opacity-40"/>';

    public const FIELDSET = '<fieldset class="fieldset py-0">';

    public static function controlNeedle(): string
    {
        return 'id="{{ $uuid }}"';
    }

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return self::formControlReplacements() + [
            // Screen readers say an example callsign in the placeholder
            // ("e.g. W1AW") as a word, so describe the field with it spelled.
            self::FIELDSET => self::FIELDSET.'@if(($spokenPlaceholder = spokenPlaceholder($attributes->get(\'placeholder\'))) !== null)<span id="{{ $uuid }}-placeholder" hidden>{{ $spokenPlaceholder }}</span>@endif',
            self::CLEAR_ICON => '<button type="button" aria-label="Clear{{ $label ? \' \'.$label : \'\' }}" class="cursor-pointer flex items-center justify-center pointer-coarse:min-h-11 pointer-coarse:min-w-11" x-on:click="$wire.set(\'{{ $modelName() }}\', \'\', {{ json_encode($attributes->wire(\'model\')->hasModifier(\'live\')) }})"><x-mary-icon name="o-x-mark" class="w-4 h-4 opacity-60" /></button>',
        ];
    }
}
