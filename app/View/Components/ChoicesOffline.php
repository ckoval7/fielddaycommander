<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\LabelsFormControl;
use Mary\View\Components\ChoicesOffline as MaryChoicesOffline;

/**
 * Mary UI's <x-choices-offline> with null-safe selection getters, and its
 * label, hint and errors linked to the search input.
 *
 * Since Alpine 3.17 (bundled with Livewire 4.4), changing an `x-data`
 * expression reconciles the old data object instead of starting fresh.
 * Deleting the old getters reads them with `this` bound to the raw data
 * object, where the parent-scope `selection` is undefined, so Mary's
 * getters throw and the component is left without its scope: the chosen
 * option never shows and `isReadonly is not defined` is logged.
 *
 * Mary fixed <x-choices> this way in robsontenorio/mary#1119 but not
 * <x-choices-offline>. Drop the getter replacements once Mary ships the
 * same fix.
 */
class ChoicesOffline extends MaryChoicesOffline
{
    use LabelsFormControl {
        templateReplacements as formControlReplacements;
    }

    /**
     * Getter expressions made null-safe, keyed by Mary's original text.
     *
     * @var array<string, string>
     */
    public const NULL_SAFE_GETTERS = [
        ': this.selection.map(i =>' => ': this.selection?.map(i =>',
        'return this.options.length == this.selection.length' => 'return this.options.length == this.selection?.length',
        ': this.selection.length == 0' => ': this.selection?.length == 0',
    ];

    public static function controlNeedle(): string
    {
        return 'x-ref="searchInput"';
    }

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return self::NULL_SAFE_GETTERS + self::formControlReplacements();
    }
}
