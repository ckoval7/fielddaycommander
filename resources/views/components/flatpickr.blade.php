@props([
    'mode' => 'datetime',
    'label' => null,
    'icon' => null,
    'hint' => null,
    'min' => null,
    'max' => null,
    'disabled' => false,
])

@php
    $uuid = 'fp-' . str()->random(8);
    $modelName = $attributes->whereStartsWith('wire:model')->first();
    $xData = "flatpickr({ mode: '{$mode}', min: " . ($min ? "'{$min}'" : 'null') . ", max: " . ($max ? "'{$max}'" : 'null') . ", model: " . ($modelName ? "'{$modelName}'" : 'null') . " })";
@endphp

<div
    wire:ignore
    x-data="{!! $xData !!}"
    class="w-full"
>
    {{-- Label --}}
    @if($label)
        <label for="{{ $uuid }}" class="pt-0 label label-text font-semibold">
            @if($icon)
                <x-icon :name="$icon" class="w-4 h-4 text-base-content/60" />
            @endif
            <span>{{ $label }}</span>
        </label>
    @endif

    {{-- Input --}}
    <input
        id="{{ $uuid }}"
        type="text"
        placeholder="{{ $mode === 'time' ? 'HH:MM' : ($mode === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:MM') }}"
        {{ $attributes->except('disabled')->class(['input input-bordered w-full']) }}
        autocomplete="off"
        @if($disabled) disabled @endif
    />

    {{-- Hint --}}
    @if($hint)
        <div class="label">
            <span class="label-text-alt text-base-content/60">{{ $hint }}</span>
        </div>
    @endif

    {{-- Error --}}
    @if($modelName)
        @error($modelName)
            <div class="label">
                <span class="label-text-alt text-error">{{ $message }}</span>
            </div>
        @enderror
    @endif
</div>
