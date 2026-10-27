<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Breadcrumbs as MaryBreadcrumbs;

/**
 * Mary UI's <x-breadcrumbs> as a named breadcrumb navigation landmark, with
 * its separators inside hidden list items so the list stays valid.
 */
class Breadcrumbs extends MaryBreadcrumbs
{
    use PatchesMaryTemplate;

    public const LIST_OPEN = '<ul {{ $attributes->merge([\'class\' => \'flex items-center\']) }} wire:key="{{ $uuid }}">';

    public const ELLIPSIS = '<span class="sm:hidden">...</span>';

    public const SEPARATOR_OPEN = "<span @class([\n                    \"hidden\",";

    public const SEPARATOR_CLOSE = "<x-mary-icon :name=\"\$separator\" @class([\$separatorClass]) />\n            </span>";

    public const LIST_CLOSE = "@endforeach\n    </ul>";

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::LIST_OPEN => '<nav aria-label="Breadcrumb">'.self::LIST_OPEN,
            self::ELLIPSIS => '<li class="sm:hidden" aria-hidden="true">...</li>',
            self::SEPARATOR_OPEN => "<li aria-hidden=\"true\" @class([\n                    \"hidden\",",
            self::SEPARATOR_CLOSE => "<x-mary-icon :name=\"\$separator\" @class([\$separatorClass]) />\n            </li>",
            self::LIST_CLOSE => "@endforeach\n    </ul></nav>",
        ];
    }
}
