<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Mary\View\Components\Table as MaryTable;

/**
 * Mary UI's <x-table> without a copy of every row in each checkbox.
 *
 * Mary writes the whole row as JSON into each selectable checkbox, only to
 * pass it along with its `row-selection` event. A logbook row's contact
 * record, with its logger and relations, came to ~3 KB a row. Nothing here
 * listens for that event, so the checkbox now sends just the row's key.
 */
class Table extends MaryTable
{
    /**
     * Template text to swap, keyed by Mary's original.
     *
     * @var array<string, string>
     */
    public const LEAN_SELECTION = [
        '@click.stop="toggleCheck($el.checked, {{ json_encode($row) }})"' => '@click.stop="toggleCheck($el.checked, {{ json_encode(data_get($row, $selectableKey)) }})"',
    ];

    public function render(): View|Closure|string
    {
        return str_replace(
            array_keys(self::LEAN_SELECTION),
            array_values(self::LEAN_SELECTION),
            parent::render(),
        );
    }
}
