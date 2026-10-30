<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components\Concerns;

use Closure;
use Illuminate\Contracts\View\View;

/**
 * Applies exact-text replacements to a Mary UI component's inline template.
 *
 * Each replacement is keyed by the Mary text it replaces, so a Mary upgrade
 * that changes that text turns the patch into a no-op instead of breaking
 * the page. `MaryTemplatePatchesTest` asserts every key still matches.
 */
trait PatchesMaryTemplate
{
    /**
     * Replacements keyed by the exact Mary template text they replace.
     *
     * @return array<string, string>
     */
    abstract public static function templateReplacements(): array;

    public function render(): View|Closure|string
    {
        $replacements = static::templateReplacements();

        return str_replace(array_keys($replacements), array_values($replacements), parent::render());
    }
}
