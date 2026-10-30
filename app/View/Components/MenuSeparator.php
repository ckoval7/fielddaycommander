<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\MenuSeparator as MaryMenuSeparator;

/**
 * Mary UI's <x-menu-separator> with its rule inside a hidden list item, so
 * the menu is a valid list for screen readers.
 */
class MenuSeparator extends MaryMenuSeparator
{
    use PatchesMaryTemplate;

    public const RULE = '<hr class="my-3 border-t-[length:var(--border)] border-base-content/10"/>';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::RULE => '<li aria-hidden="true" class="pointer-events-none"><hr class="my-3 !p-0 border-t-[length:var(--border)] border-base-content/10"/></li>',
        ];
    }
}
