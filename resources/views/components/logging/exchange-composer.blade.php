{{--
Exchange Composer Component

The exchange input docked under the QSO log, with autocomplete suggestions,
keyboard recall, and the Log/Clear ↔ Save/Delete/Cancel button morph.

The enclosing Alpine scope must provide isRecalling, recalledExchange,
logContact(input), saveRecalled(input), deleteRecalled(input),
exitRecall(input), recallUp(input) and recallDown(input). The Livewire
component must provide exchangeInput, suggestions, selectSuggestion() and
clearInput().

Props:
- $placeholder: string - Example exchange shown in the empty input
- $suggestions: array - Autocomplete suggestions from the Livewire component
- $duplicateWarning: string|null - Duplicate warning to show, if any

Slots:
- $slot: Page-specific alerts and hints, shown above the duplicate warning
--}}

@props([
    'placeholder' => 'W1AW 3A CT',
    'suggestions' => [],
    'duplicateWarning' => null,
])

<div class="border-t border-base-300 p-3 space-y-2" x-data="{ si: -1 }">
    {{-- Recall Mode Indicator --}}
    <template x-if="isRecalling">
        <div class="alert alert-info py-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
            </svg>
            <span>
                Editing <span x-text="recalledExchange" class="font-bold font-mono"></span>
                — edit it below, then tap
                <span class="font-semibold">Save</span>,
                <span class="font-semibold">Delete</span>, or
                <span class="font-semibold">Cancel</span>.
            </span>
        </div>
    </template>

    {{ $slot }}

    @if($duplicateWarning)
        <x-alert x-show="!isRecalling" icon="phosphor-warning" class="alert-warning">
            Duplicate: {{ $duplicateWarning }}
        </x-alert>
    @endif

    <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative flex-1">
            <input
                type="text"
                id="exchange-input"
                wire:model.live.debounce.300ms="exchangeInput"
                x-ref="exchangeInput"
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
                            : (($refs.exchangeInput.value = ''), $wire.clearInput()))
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
                @keydown.tab.prevent="si >= 0 && $wire.suggestions?.length > 0 ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1) : null"
                @contact-logged.window="$refs.exchangeInput.focus(); $refs.exchangeInput.select(); si = -1"
                @suggestion-selected.window="$nextTick(() => { $refs.exchangeInput.focus(); si = -1 })"
                class="input input-bordered input-lg w-full text-2xl font-mono uppercase tracking-wider"
                placeholder="{{ $placeholder }}"
                aria-label="Exchange input"
                autofocus
            />

            {{-- Autocomplete Suggestions --}}
            @if(count($suggestions) > 0)
                <div class="absolute z-50 w-full bottom-full mb-1 bg-base-100 border border-base-300 rounded-box shadow-lg max-h-48 overflow-y-auto">
                    @foreach($suggestions as $index => $suggestion)
                        <button
                            wire:click="selectSuggestion('{{ $suggestion['exchange'] }}')"
                            :class="{ 'bg-primary text-primary-content': si === {{ $index }} }"
                            @mouseenter="si = {{ $index }}"
                            class="w-full px-3 py-2 text-left hover:bg-base-200 flex items-center justify-between font-mono"
                            type="button"
                        >
                            <span class="font-bold">{{ $suggestion['exchange'] }}</span>
                            <span class="text-xs opacity-60" :class="{ 'text-primary-content/60': si === {{ $index }} }">{{ $suggestion['worked_on'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
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
</div>
