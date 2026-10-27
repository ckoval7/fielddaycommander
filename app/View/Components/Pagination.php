<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Pagination as MaryPagination;

/**
 * Mary UI's <x-pagination> with a named rows-per-page picker.
 */
class Pagination extends MaryPagination
{
    use PatchesMaryTemplate;

    public const PER_PAGE_SELECT = '<select id="{{ $uuid }}"';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::PER_PAGE_SELECT => self::PER_PAGE_SELECT.' aria-label="Rows per page"',
        ];
    }
}
