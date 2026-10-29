<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Hooks;

use App\Support\HtmlMinifier;
use App\Support\IconSprite;
use Livewire\ComponentHook;

/**
 * Trims each Livewire component's HTML, on the first page load and on every
 * update alike so morphs compare like with like.
 *
 * Icons rendered by the component share one sprite placed inside its root,
 * so references survive any morph of that component; and template
 * whitespace and comments are stripped (see HtmlMinifier).
 */
class LeanComponentHtml extends ComponentHook
{
    /**
     * Livewire passes the view and its data; neither is needed here.
     */
    public function render(): callable
    {
        $sprite = app(IconSprite::class);
        $sprite->begin();

        return function (string $html, callable $replaceHtml) use ($sprite): void {
            $replaceHtml(HtmlMinifier::minify(IconSprite::appendToRoot($html, $sprite->end())));
        };
    }
}
