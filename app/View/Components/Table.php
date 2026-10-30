<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Table as MaryTable;

/**
 * Mary UI's <x-table> with column headers scoped to their columns, named
 * selection checkboxes, its unlabelled spacer headers hidden, and without a
 * copy of every row in each checkbox.
 *
 * Mary writes the whole row as JSON into each selectable checkbox, only to
 * pass it along with its `row-selection` event. A logbook row's contact
 * record, with its logger and relations, came to ~3 KB a row. Nothing here
 * listens for that event, so the checkbox now sends just the row's key.
 */
class Table extends MaryTable
{
    use PatchesMaryTemplate;

    public const HEADER_CLASS = 'class="@if($isSortable($header)) cursor-pointer hover:bg-base-200 @endif {{ $header[\'class\'] ?? \' \' }}"';

    public const SPACER_HEADER = '<th class="w-1"></th>';

    public const CHECK_ALL = 'id="checkAll-{{ $uuid }}"';

    public const ROW_CHECKBOX = 'id="checkbox-{{ $uuid }}-{{ $k }}"';

    public const ROW_SELECTION = '@click.stop="toggleCheck($el.checked, {{ json_encode($row) }})"';

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
            self::ROW_SELECTION => '@click.stop="toggleCheck($el.checked, {{ json_encode(data_get($row, $selectableKey)) }})"',
        ];
    }
}
