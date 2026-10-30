<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\MenuItem as MaryMenuItem;

/**
 * Mary UI's <x-menu-item> that keyboards can reach when it has no link.
 *
 * An item that only runs an action (`wire:click`, `@click`) renders as an
 * `<a>` with no `href`, which is skipped by Tab and announced as plain text.
 * Those items become focusable buttons that Enter and Space activate.
 */
class MenuItem extends MaryMenuItem
{
    use PatchesMaryTemplate;

    public const HREF_CHECK = '@if($getHref())';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::HREF_CHECK => '@if(! $getHref() && ! $disabled) role="button" tabindex="0" @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()" @endif '.self::HREF_CHECK,
        ];
    }
}
