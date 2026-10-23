{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@props(['item', 'size' => 'sm'])

@php
    $sizeClasses = match($size) {
        'lg' => 'w-16 h-16',
        default => 'w-12 h-12',
    };
    $iconSize = match($size) {
        'lg' => 'w-8 h-8',
        default => 'w-6 h-6',
    };
@endphp

@if($item->photo_path)
    <button
        type="button"
        class="flex-shrink-0 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        wire:click="viewPhoto({{ Js::from($item->photo_path) }}, {{ Js::from($item->make.' '.$item->model) }})"
    >
        <img
            src="{{ asset('storage/' . $item->photo_path) }}"
            alt="{{ $item->make }} {{ $item->model }}"
            class="{{ $sizeClasses }} object-cover rounded cursor-pointer hover:opacity-80 transition-opacity"
        />
    </button>
@else
    <div class="{{ $sizeClasses }} bg-base-300 rounded flex items-center justify-center flex-shrink-0">
        <x-icon name="phosphor-camera" class="{{ $iconSize }} text-base-content/50" />
    </div>
@endif
