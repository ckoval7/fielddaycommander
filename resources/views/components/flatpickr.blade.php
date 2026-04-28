@props([
    'mode' => 'datetime',
    'label' => null,
    'icon' => null,
    'hint' => null,
    'min' => null,
    'max' => null,
    'disabled' => false,
    'nowButton' => false,
    'nowUtc' => true,
])

@php
    $uuid = 'fp-' . str()->random(8);
    $modelName = $attributes->whereStartsWith('wire:model')->first();
    $xData = "flatpickr({ mode: '{$mode}', min: " . ($min ? "'{$min}'" : 'null') . ", max: " . ($max ? "'{$max}'" : 'null') . ", model: " . ($modelName ? "'{$modelName}'" : 'null') . ", nowUtc: " . ($nowUtc ? 'true' : 'false') . " })";
@endphp

<div
    x-data="{!! $xData !!}"
    class="w-full"
>
    <fieldset class="fieldset py-0">
        {{-- Label --}}
        @if($label)
            <legend class="fieldset-legend mb-0.5">
                {{ $label }}

                @if($attributes->get('required'))
                    <span class="text-error">*</span>
                @endif
            </legend>
        @endif

        {{-- Input (wire:ignore protects flatpickr's DOM mutations from Livewire morphing) --}}
        <div wire:ignore>
            @if($nowButton)
                <div class="flex items-end gap-1.5">
            @endif

            <label class="input w-full">
                @if($icon)
                    <x-icon :name="$icon" class="pointer-events-none w-4 h-4 opacity-40" />
                @endif

                <input
                    id="{{ $uuid }}"
                    type="text"
                    placeholder="{{ $mode === 'time' ? 'HH:MM' : ($mode === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:MM') }}"
                    {{ $attributes->except('disabled')->class(['w-full']) }}
                    autocomplete="off"
                    @if($disabled) disabled @endif
                />
            </label>

            @if($nowButton)
                    <button
                        type="button"
                        class="btn btn-sm btn-soft btn-secondary"
                        x-on:click="setNow()"
                        title="Set to current {{ $nowUtc ? 'UTC' : 'local' }} time"
                    >
                        Now
                    </button>
                </div>
            @endif
        </div>

        {{-- Hint --}}
        @if($hint)
            <div class="fieldset-label">{{ $hint }}</div>
        @endif

        {{-- Error --}}
        @if($modelName)
            @error($modelName)
                <div class="text-error">{{ $message }}</div>
            @enderror
        @endif
    </fieldset>
</div>
