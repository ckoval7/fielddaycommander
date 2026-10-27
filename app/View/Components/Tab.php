<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Tab as MaryTab;

/**
 * Mary UI's <x-tab> as a keyboard-operable ARIA tab.
 *
 * Mary renders each tab as a `<label>` with only a click handler, so it
 * can't be reached or switched from the keyboard and screen readers don't
 * know it is a tab. Each label becomes a `tab` button, which the arrow keys
 * move between, tied to its `tabpanel`.
 */
class Tab extends MaryTab
{
    use PatchesMaryTemplate;

    public const LABEL_OPEN = "<label\n";

    public const LABEL_CLOSE = '</label>';

    public const LABEL_CLICK = '@click="selected = \'{{ $name }}\'"';

    public const PANEL = 'x-show="selected == \'{{ $name }}\'"';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::LABEL_OPEN => "<button type=\"button\"\n",
            self::LABEL_CLOSE => '</button>',
            self::LABEL_CLICK => self::LABEL_CLICK
                .' role="tab" id="{{ $uuid() }}-tab-{{ $name }}" aria-controls="{{ $uuid() }}-panel-{{ $name }}"'
                .' :aria-selected="(selected === \'{{ $name }}\').toString()" @if($disabled) disabled @endif'
                .' @keydown.arrow-right.prevent="$el.nextElementSibling?.focus()" @keydown.arrow-left.prevent="$el.previousElementSibling?.focus()"',
            self::PANEL => self::PANEL.' role="tabpanel" id="{{ $uuid() }}-panel-{{ $name }}" aria-labelledby="{{ $uuid() }}-tab-{{ $name }}"',
        ];
    }
}
