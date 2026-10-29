{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Exchange Composer Component

The exchange input docked under the QSO log, with autocomplete suggestions,
keyboard recall, and the Log/Clear ↔ Save/Delete/Cancel button morph.

The enclosing Alpine scope must provide isRecalling, recalledExchange,
logContact(input), saveRecalled(input), deleteRecalled(input),
exitRecall(input), recallUp(input) and recallDown(input). The Livewire
component must provide exchangeInput, suggestions, selectSuggestion() and
clearInput().

With $advanced, the enclosing Alpine scope must also provide advanced,
advancedOpen, advancedSummary, toggleAdvanced(focusFirst), clearAdvanced(),
offBandSuggestion, frequencyPlaceholder, reportPlaceholder and
reportInputMode.

Props:
- $advanced: bool - Show the Advanced Fields panel (frequency, reports, grid, name, notes)
- $placeholder: string - Example exchange shown in the empty input
- $suggestions: array - Autocomplete suggestions from the Livewire component
- $duplicateWarning: string|null - Duplicate warning to show, if any
- $participantWarning: string|null - Own-participant contact warning to show, if any
- $error: string|null - Server-side parse error, announced to screen readers (pages that
  parse in the browser announce their own errors)

Slots:
- $slot: Page-specific alerts and hints, shown above the duplicate warning
--}}

@props([
    'advanced' => false,
    'placeholder' => 'W1AW 3A CT',
    'suggestions' => [],
    'duplicateWarning' => null,
    'participantWarning' => null,
    'error' => null,
])

{{-- Arrowing through suggestions reads each one out; focus stays in the exchange box --}}
<div
    class="border-t border-base-300 p-3 space-y-2"
    x-data="{ si: -1 }"
    x-init="$watch('si', (index) => { const option = document.getElementById('exchange-suggestion-' + index); if (option) { window.fdcAnnounce?.(option.innerText) } })"
>
    {{-- Recall Mode Indicator --}}
    <template x-if="isRecalling">
        <div class="alert alert-info py-2">
            <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
            </svg>
            <span>
                Editing <span x-text="recalledExchange" class="font-bold font-mono"></span>.
                Edit below, then choose
                <span class="font-semibold">Save</span>,
                <span class="font-semibold">Delete</span>, or
                <span class="font-semibold">Cancel</span>.
            </span>
        </div>
    </template>

    {{ $slot }}

    @if($duplicateWarning)
        <x-alert x-show="!isRecalling" icon="phosphor-warning" class="alert-warning">
            Dupe: {{ $duplicateWarning }}
        </x-alert>
    @endif

    @if($participantWarning)
        <x-alert x-show="!isRecalling" icon="phosphor-warning" class="alert-warning">
            {{ $participantWarning }}
        </x-alert>
    @endif

    {{-- Always rendered, so screen readers hear a dupe or error as soon as it appears --}}
    <output id="exchange-warnings" class="sr-only" aria-live="polite" aria-atomic="true">
        @if($duplicateWarning)Dupe: {{ $duplicateWarning }}.@endif
        {{ $participantWarning }}
    </output>
    <div class="sr-only" role="alert" aria-atomic="true">{{ $error }}</div>
    <p id="exchange-help" class="sr-only">Callsign, class, and section, for example {{ $placeholder }}. Enter logs. Up arrow in an empty exchange recalls earlier contacts.</p>

    <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative flex-1">
            <input
                type="text"
                id="exchange-input"
                wire:model.live.debounce.300ms="exchangeInput"
                x-ref="exchangeInput"
                x-init="$nextTick(() => $el.focus())"
                @input="si = -1"
                @keydown.enter.prevent="
                    si >= 0 && $wire.suggestions?.length > 0
                        ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1)
                        : (isRecalling
                            ? saveRecalled($refs.exchangeInput)
                            : logContact($refs.exchangeInput))
                "
                @keydown.escape.prevent="
                    si >= 0
                        ? (si = -1)
                        : (isRecalling
                            ? exitRecall($refs.exchangeInput)
                            : (($refs.exchangeInput.value = ''), $wire.clearInput()@if($advanced), clearAdvanced()@endif))
                "
                @keydown.arrow-down.prevent="
                    $wire.suggestions?.length > 0
                        ? (si = Math.min(si + 1, $wire.suggestions.length - 1))
                        : (isRecalling ? recallDown($refs.exchangeInput) : null)
                "
                @keydown.arrow-up.prevent="
                    $wire.suggestions?.length > 0
                        ? (si = Math.max(si - 1, -1))
                        : ($refs.exchangeInput.value.trim() === '' || isRecalling
                            ? recallUp($refs.exchangeInput)
                            : null)
                "
                @keydown.delete="
                    if (isRecalling) { $event.preventDefault(); deleteRecalled($refs.exchangeInput); }
                "
                @keydown.tab="
                    if (si >= 0 && $wire.suggestions?.length > 0) {
                        $event.preventDefault();
                        $wire.selectSuggestion($wire.suggestions[si].exchange);
                        si = -1;
                    }@if($advanced) else if (advancedOpen && ! $event.shiftKey) {
                        $event.preventDefault();
                        document.getElementById('log-frequency')?.focus();
                    }@endif
                "
                @contact-logged.window="$refs.exchangeInput.focus(); $refs.exchangeInput.select(); si = -1"
                @suggestion-selected.window="$nextTick(() => { $refs.exchangeInput.focus(); si = -1 })"
                class="input input-lg w-full text-2xl font-mono uppercase tracking-wider"
                placeholder="{{ $placeholder }}"
                aria-label="Exchange"
                aria-describedby="exchange-warnings exchange-help"
                aria-autocomplete="list"
                autocomplete="off"
                autocapitalize="characters"
                autocorrect="off"
                spellcheck="false"
                enterkeyhint="send"
            />

            {{-- Autocomplete Suggestions --}}
            <div id="exchange-suggestions" @class([
                'absolute z-50 w-full bottom-full mb-1 bg-base-100 border border-base-300 rounded-box shadow-lg max-h-48 overflow-y-auto',
                'hidden' => count($suggestions) === 0,
            ])>
                @if(count($suggestions) > 0)
                    @foreach($suggestions as $index => $suggestion)
                        <button
                            id="exchange-suggestion-{{ $index }}"
                            tabindex="-1"
                            wire:click="selectSuggestion({{ Js::from($suggestion['exchange']) }})"
                            :class="{ 'bg-primary text-primary-content': si === {{ $index }} }"
                            @mouseenter="si = {{ $index }}"
                            class="w-full px-3 py-2 text-left flex items-center justify-between font-mono"
                            type="button"
                        >
                            <span class="font-bold">{{ $suggestion['exchange'] }}</span>
                            <span class="text-xs opacity-70">{{ $suggestion['worked_on'] }}</span>
                        </button>
                    @endforeach
                @endif
            </div>
        </div>
        <template x-if="!isRecalling">
            <div class="flex gap-2 sm:contents">
                <x-button
                    label="Log"
                    icon="phosphor-check"
                    class="btn-primary btn-lg flex-1 sm:flex-initial"
                    @click="logContact($refs.exchangeInput)"
                    tooltip="Enter"
                    tooltip-position="tooltip-bottom"
                />
                <x-button
                    label="Clear"
                    icon="phosphor-x"
                    class="btn-ghost btn-lg flex-1 sm:flex-initial"
                    wire:click="clearInput"
                    x-on:click="{{ $advanced ? 'clearAdvanced()' : '' }}"
                    tooltip="Esc"
                    tooltip-position="tooltip-bottom"
                />
            </div>
        </template>
        <template x-if="isRecalling">
            <div class="flex gap-2 sm:contents">
                <button type="button"
                    class="btn btn-primary btn-lg flex-1 min-w-0 max-sm:px-2 sm:flex-initial"
                    @click="saveRecalled($refs.exchangeInput)">
                    <x-icon name="phosphor-check" class="w-5 h-5 max-sm:hidden" /> Save
                </button>
                <button type="button"
                    class="btn btn-error btn-lg flex-1 min-w-0 max-sm:px-2 sm:flex-initial"
                    @click="deleteRecalled($refs.exchangeInput)">
                    <x-icon name="phosphor-trash" class="w-5 h-5 max-sm:hidden" /> Delete
                </button>
                <button type="button"
                    class="btn btn-ghost btn-lg flex-1 min-w-0 max-sm:px-2 sm:flex-initial"
                    @click="exitRecall($refs.exchangeInput)">
                    <x-icon name="phosphor-x" class="w-5 h-5 max-sm:hidden" /> Cancel
                </button>
            </div>
        </template>
    </div>

    @if($advanced)
        @php
            // Enter logs (or saves a recalled contact) from any field; Esc goes back to the exchange.
            $fieldKeys = '@keydown.enter.prevent="isRecalling ? saveRecalled($refs.exchangeInput) : logContact($refs.exchangeInput)" '
                .'@keydown.escape.prevent="$refs.exchangeInput.focus()"';
        @endphp
        <div
            data-advanced-fields
            @keydown.window="
                if ($event.altKey && ! $event.ctrlKey && ! $event.metaKey && $event.code === 'KeyA') {
                    $event.preventDefault();
                    toggleAdvanced(true);
                }
            "
        >
            <button
                type="button"
                class="btn btn-ghost btn-sm gap-1.5 px-2 font-normal"
                @click="toggleAdvanced(true)"
                :aria-expanded="advancedOpen"
                aria-controls="log-advanced-panel"
            >
                <x-icon name="phosphor-caret-right" class="w-4 h-4 transition-transform motion-reduce:transition-none" ::class="advancedOpen && 'rotate-90'" />
                <span>Advanced fields</span>
                <span x-show="!advancedOpen && advancedSummary" x-text="advancedSummary" class="font-mono text-xs text-base-content/60 truncate max-w-48 sm:max-w-80"></span>
                <kbd class="kbd kbd-xs max-sm:hidden pointer-coarse:hidden">Alt+A</kbd>
            </button>

            <div
                id="log-advanced-panel"
                x-show="advancedOpen"
                x-cloak
                class="grid grid-cols-6 sm:grid-cols-12 gap-2 pt-1"
            >
                <label class="col-span-2 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">Freq (MHz)</span>
                    <input
                        id="log-frequency"
                        type="text"
                        x-model="advanced.frequency"
                        @input="offBandSuggestion = null; parseError = ''"
                        @keydown.shift.tab="$event.preventDefault(); $refs.exchangeInput.focus()"
                        {!! $fieldKeys !!}
                        inputmode="decimal"
                        autocomplete="off"
                        maxlength="12"
                        :placeholder="frequencyPlaceholder"
                        class="input input-sm w-full font-mono"
                    />
                </label>
                <label class="col-span-2 sm:col-span-1 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">RST sent</span>
                    <input
                        id="log-rst-sent"
                        type="text"
                        x-model="advanced.rst_sent"
                        {!! $fieldKeys !!}
                        :inputmode="reportInputMode"
                        autocomplete="off"
                        maxlength="4"
                        :placeholder="reportPlaceholder"
                        class="input input-sm w-full font-mono"
                    />
                </label>
                <label class="col-span-2 sm:col-span-1 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">RST rcvd</span>
                    <input
                        id="log-rst-received"
                        type="text"
                        x-model="advanced.rst_received"
                        {!! $fieldKeys !!}
                        :inputmode="reportInputMode"
                        autocomplete="off"
                        maxlength="4"
                        :placeholder="reportPlaceholder"
                        class="input input-sm w-full font-mono"
                    />
                </label>
                <label class="col-span-3 sm:col-span-2 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">Grid</span>
                    <input
                        id="log-grid"
                        type="text"
                        x-model="advanced.grid_square"
                        {!! $fieldKeys !!}
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        maxlength="6"
                        placeholder="FN31pr"
                        class="input input-sm w-full font-mono"
                    />
                </label>
                <label class="col-span-3 sm:col-span-2 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">Name</span>
                    <input
                        id="log-contact-name"
                        type="text"
                        x-model="advanced.contact_name"
                        {!! $fieldKeys !!}
                        autocomplete="off"
                        autocapitalize="words"
                        maxlength="50"
                        class="input input-sm w-full"
                    />
                </label>
                <label class="col-span-6 sm:col-span-4 flex flex-col gap-1">
                    <span class="text-xs text-base-content/70">Notes</span>
                    <input
                        id="log-notes"
                        type="text"
                        x-model="advanced.notes"
                        {!! $fieldKeys !!}
                        autocomplete="off"
                        maxlength="500"
                        class="input input-sm w-full"
                    />
                </label>
            </div>
        </div>
    @endif
</div>
