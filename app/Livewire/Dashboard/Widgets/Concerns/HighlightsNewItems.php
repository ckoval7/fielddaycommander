<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets\Concerns;

/**
 * Tracks which list items are new since the widget's previous render.
 *
 * The comparison lives server-side because the client-side approach could not
 * work: the item IDs were baked into x-data, which changes on every render,
 * so Alpine re-ran init() and flagged every row as new on each poll.
 */
trait HighlightsNewItems
{
    /**
     * Item keys from the previous render; null until the first render so the
     * initial page load highlights nothing.
     *
     * @var array<int, string>|null
     */
    public ?array $renderedItemKeys = null;

    /**
     * Return the keys that were not present on the previous render, and
     * remember the current keys for the next comparison.
     *
     * @param  array<int, string>  $currentKeys
     * @return array<int, string>
     */
    protected function newItemKeys(array $currentKeys): array
    {
        $newKeys = $this->renderedItemKeys === null
            ? []
            : array_values(array_diff($currentKeys, $this->renderedItemKeys));

        $this->renderedItemKeys = array_values($currentKeys);

        return $newKeys;
    }
}
