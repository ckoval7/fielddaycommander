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
    <img
        src="{{ asset('storage/' . $item->photo_path) }}"
        alt="{{ $item->make }} {{ $item->model }}"
        class="{{ $sizeClasses }} object-cover rounded cursor-pointer hover:opacity-80 transition-opacity flex-shrink-0"
        wire:click="viewPhoto('{{ $item->photo_path }}', '{{ $item->make }} {{ $item->model }}')"
    />
@else
    <div class="{{ $sizeClasses }} bg-base-300 rounded flex items-center justify-center flex-shrink-0">
        <x-icon name="o-camera" class="{{ $iconSize }} text-base-content/50" />
    </div>
@endif
