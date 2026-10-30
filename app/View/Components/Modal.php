<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Modal as MaryModal;

/**
 * Mary UI's <x-modal>, announced as a modal dialog named by its title, with
 * a named close button.
 */
class Modal extends MaryModal
{
    use PatchesMaryTemplate;

    public const DIALOG_ATTRIBUTES = '{{ $attributes->except(\'wire:model\')->class(["modal"]) }}';

    public const CLOSE_BUTTON = 'icon="o-x-mark" type="submit" tabindex="-1" />';

    public const CLOSE_BUTTON_WIRE = 'icon="o-x-mark" @click="$wire.{{ $attributes->wire(\'model\')->value() }} = false" tabindex="-1" />';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::DIALOG_ATTRIBUTES => self::DIALOG_ATTRIBUTES.' aria-modal="true" @if($title && ! $attributes->hasAny([\'aria-label\', \'aria-labelledby\'])) aria-label="{{ $title }}" @endif',
            self::CLOSE_BUTTON => 'icon="o-x-mark" type="submit" tabindex="-1" aria-label="Close" />',
            self::CLOSE_BUTTON_WIRE => 'icon="o-x-mark" @click="$wire.{{ $attributes->wire(\'model\')->value() }} = false" tabindex="-1" aria-label="Close" />',
        ];
    }
}
