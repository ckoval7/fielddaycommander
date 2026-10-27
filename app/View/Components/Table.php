<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Table as MaryTable;

/**
 * Mary UI's <x-table> with column headers scoped to their columns, named
 * selection checkboxes, and its unlabelled spacer headers hidden.
 */
class Table extends MaryTable
{
    use PatchesMaryTemplate;

    public const HEADER_CLASS = 'class="@if($isSortable($header)) cursor-pointer hover:bg-base-200 @endif {{ $header[\'class\'] ?? \' \' }}"';

    public const SPACER_HEADER = '<th class="w-1"></th>';

    public const CHECK_ALL = 'id="checkAll-{{ $uuid }}"';

    public const ROW_CHECKBOX = 'id="checkbox-{{ $uuid }}-{{ $k }}"';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::HEADER_CLASS => 'scope="col" '.self::HEADER_CLASS,
            self::SPACER_HEADER => '<th class="w-1" aria-hidden="true"></th>',
            self::CHECK_ALL => self::CHECK_ALL.' aria-label="Select all rows"',
            self::ROW_CHECKBOX => self::ROW_CHECKBOX.' aria-label="Select row {{ $k + 1 }}"',
        ];
    }
}
