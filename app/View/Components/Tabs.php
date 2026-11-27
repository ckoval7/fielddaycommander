<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Tabs as MaryTabs;

/**
 * Mary UI's <x-tabs> with its tab strip exposed as a `tablist`. See Tab.
 */
class Tabs extends MaryTabs
{
    use PatchesMaryTemplate;

    public const LABELS = '<div id="{{ $uuid() }}-labels" wire:ignore';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::LABELS => self::LABELS.' role="tablist"',
        ];
    }
}
