<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Button as MaryButton;

/**
 * Mary UI's <x-button> with an accessible name when it shows only an icon.
 *
 * Mary puts the tooltip in `data-tip`, which screen readers ignore, so an
 * icon button with a tooltip and no label is announced as just "button".
 * The tooltip becomes its `aria-label`. A `responsive` label hidden below
 * `lg` stays readable by screen readers instead of being removed.
 */
class Button extends MaryButton
{
    use PatchesMaryTemplate;

    public const TOOLTIP = 'data-tip="{{ $tooltip }}"';

    public const RESPONSIVE_LABEL = '<span @class(["hidden lg:block" => $responsive ])>';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::TOOLTIP => self::TOOLTIP.' @if(! $label && $slot->isEmpty() && ! $attributes->hasAny([\'aria-label\', \'aria-labelledby\'])) aria-label="{{ $tooltip }}" @endif',
            self::RESPONSIVE_LABEL => '<span @class(["sr-only lg:not-sr-only lg:block" => $responsive ])>',
        ];
    }
}
