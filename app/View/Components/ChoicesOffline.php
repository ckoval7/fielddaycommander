<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Mary\View\Components\ChoicesOffline as MaryChoicesOffline;

/**
 * Mary UI's <x-choices-offline>, with option rows rendered in the browser.
 *
 * Mary renders every option on the server as a full list item with its own
 * Alpine handlers, and embeds each option's whole record as JSON. A time zone
 * picker came to ~900 KB and the logbook's pickers to ~220 KB each. This
 * version sends only each option's value, label and sub-label, and the
 * `choicesOffline` Alpine component (resources/js/components/choices-offline.js)
 * renders the visible rows from one template.
 *
 * It keeps Mary's constructor, so existing props still work: label, hint,
 * icon, single, searchable, required, readonly, disabled, placeholder,
 * option-value, option-label, option-sub-label and no-result-text. Mary's
 * item/selection slots, compact and allow-all are not supported; nothing in
 * the app uses them.
 */
class ChoicesOffline extends MaryChoicesOffline
{
    /**
     * Rows rendered at once; typing narrows the rest.
     */
    public const RENDER_LIMIT = 100;

    /**
     * Options reduced to what the picker shows.
     *
     * @return list<array{v: mixed, l: string, s?: string}>
     */
    public function leanOptions(): array
    {
        return collect($this->options)
            ->map(function (mixed $option): array {
                $lean = [
                    'v' => $this->normalizeValue(data_get($option, $this->optionValue)),
                    'l' => (string) data_get($option, $this->optionLabel),
                ];

                if ($this->optionSubLabel) {
                    $lean['s'] = (string) data_get($option, $this->optionSubLabel);
                }

                return $lean;
            })
            ->values()
            ->all();
    }

    /**
     * The options as JSON that is safe inside a single-quoted attribute.
     */
    public function optionsAttribute(): string
    {
        return json_encode(
            $this->leanOptions(),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Match Mary's value handling: numeric strings become numbers unless
     * they have a leading zero, so the selection compares like it did.
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($this->valuesAsString || $this->escapeValues) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value) && ! str_starts_with($value, '0')) {
            return $value + 0;
        }

        return $value;
    }

    public function render(): View|Closure|string
    {
        return <<<'HTML'
            <div
                x-data="choicesOffline({
                    selection: @entangle($attributes->wire('model')),
                    single: {{ json_encode((bool) $single) }},
                    searchable: {{ json_encode((bool) $searchable) }},
                    required: {{ json_encode($isRequired()) }},
                    readonly: {{ json_encode($isReadonly()) }},
                    disabled: {{ json_encode($isDisabled()) }},
                    limit: {{ \App\View\Components\ChoicesOffline::RENDER_LIMIT }},
                })"
                data-options='{!! $optionsAttribute() !!}'
                @click.outside="close()"
                @keydown.escape="close()"
                @focusout="closeIfFocusLeft($event)"
                @keydown.down.prevent="open ? focusOption(1) : show()"
                @keydown.up.prevent="focusOption(-1)"
            >
                <fieldset class="fieldset py-0">
                    @if($label)
                        <legend class="fieldset-legend mb-0.5">
                            {{ $label }}
                            @if($isRequired())
                                <span class="text-error">*</span>
                            @endif
                        </legend>
                    @endif

                    <label
                        x-ref="container"
                        @if(!$isDisabled() && !$isReadonly()) @click="show()" @endif
                        {{ $attributes->whereStartsWith('class')->class([
                            'select w-full min-h-[var(--size)] h-auto ps-2.5',
                            'border-dashed' => $isReadonly(),
                            '!select-error' => $errorFieldName() && $errors->has($errorFieldName()) && !$omitError,
                        ]) }}
                    >
                        @if($icon)
                            <x-mary-icon :name="$icon" class="pointer-events-none w-4 h-4 opacity-40" />
                        @endif

                        <div class="w-full py-0.5 min-h-3 content-center text-wrap">
                            <template x-for="option in selectedOptions" :key="option.v">
                                <span @class(['m-0.5 !inline-block !h-auto', 'badge badge-soft' => !$single])>
                                    <span x-text="option.l"></span>
                                    {{-- Not a <button>: the field is a <label>, which forwards clicks to its first button. --}}
                                    @if(!$single && !$isDisabled() && !$isReadonly())
                                        <span
                                            role="button"
                                            tabindex="0"
                                            class="align-middle cursor-pointer hover:text-error"
                                            @click.stop.prevent="remove(option.v)"
                                            @keydown.enter.stop.prevent="remove(option.v)"
                                            @keydown.space.stop.prevent="remove(option.v)"
                                            :aria-label="'Remove ' + option.l"
                                        ><x-mary-icon name="phosphor-x" class="w-4 h-4" /></span>
                                    @endif
                                </span>
                            </template>

                            <span x-show="!open && isSelectionEmpty" class="text-base-content/40">{{ $attributes->get('placeholder') }}</span>

                            <input
                                x-ref="searchInput"
                                x-model="search"
                                @input="show(); resizeInput()"
                                @focus="show()"
                                :required="required && isSelectionEmpty"
                                role="combobox"
                                aria-controls="{{ $uuid }}-list"
                                aria-autocomplete="list"
                                :aria-expanded="open"
                                @if($label) aria-label="{{ $label }}" @endif
                                autocomplete="off"
                                class="w-1 !inline-block outline-hidden"
                                {{ $attributes->whereStartsWith('@') }}
                                @if($isReadonly() || $isDisabled() || !$searchable) readonly @endif
                                @if($isDisabled()) disabled @endif
                            />
                        </div>
                    </label>

                    @if(!$omitError && $errors->has($errorFieldName()))
                        @foreach($errors->get($errorFieldName()) as $message)
                            @foreach(\Illuminate\Support\Arr::wrap($message) as $line)
                                <div class="{{ $errorClass }}">{{ $line }}</div>
                                @break($firstErrorOnly)
                            @endforeach
                            @break($firstErrorOnly)
                        @endforeach
                    @endif

                    @if($hint)
                        <div class="{{ $hintClass }}">{{ $hint }}</div>
                    @endif
                </fieldset>

                <div x-cloak x-show="open" class="relative">
                    <div
                        x-ref="list"
                        id="{{ $uuid }}-list"
                        role="listbox"
                        @if(!$single) aria-multiselectable="true" @endif
                        class="{{ $height }} w-full absolute z-10 shadow-xl bg-base-100 border border-base-content/10 rounded-lg cursor-pointer overflow-y-auto overscroll-contain"
                        x-anchor.bottom-start="$refs.container"
                    >
                        <div x-show="matches.length === 0" class="p-3 font-bold text-base-content/70">{{ $noResultText }}</div>

                        <template x-for="option in visibleOptions" :key="option.v">
                            <div
                                role="option"
                                tabindex="-1"
                                :aria-selected="isActive(option.v)"
                                @click="toggle(option.v)"
                                @keydown.enter.prevent="toggle(option.v)"
                                @keydown.space.prevent="toggle(option.v)"
                                :class="isActive(option.v) ? 'border-s-base-content' : 'border-base-content/10'"
                                class="border-s-4 px-3 py-3 pointer-coarse:min-h-11 hover:bg-base-200 focus:bg-base-200 focus:outline-none"
                            >
                                <div class="font-semibold truncate" x-text="option.l"></div>
                                <div x-show="option.s" class="text-base-content/50 text-sm truncate" x-text="option.s"></div>
                            </div>
                        </template>

                        <div x-show="hiddenCount > 0" class="p-3 text-sm text-base-content/60" x-text="hiddenCount + ' more. Keep typing to narrow the list.'"></div>
                    </div>
                </div>
            </div>
            HTML;
    }
}
