<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Header as MaryHeader;

/**
 * Mary UI's <x-header> with a `heading-level` attribute.
 *
 * Mary renders the title as an `<h1>` (`use-h1`) or a plain `<div>`, so a
 * section header inside a page is either a second page heading or not a
 * heading at all. `heading-level="2"` renders an `<h2>`, and so on. The
 * subtitle is darkened to pass contrast.
 */
class Header extends MaryHeader
{
    use PatchesMaryTemplate;

    public const WRAPPER_ATTRIBUTES = '{{ $attributes->class(["mb-10", "mary-header-anchor" => $withAnchor]) }}';

    public const TITLE_OPEN = '{!! "<{$titleTag}" !!}';

    public const SUBTITLE = '"text-base-content/50 text-sm mt-1"';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::WRAPPER_ATTRIBUTES => '{{ $attributes->except(\'heading-level\')->class(["mb-10", "mary-header-anchor" => $withAnchor]) }}',
            self::SUBTITLE => '"text-base-content/70 text-sm mt-1"',
            self::TITLE_OPEN => '@php($titleTag = $attributes->has(\'heading-level\') ? \'h\'.max(1, min(6, (int) $attributes->get(\'heading-level\'))) : $titleTag)'.self::TITLE_OPEN,
        ];
    }
}
