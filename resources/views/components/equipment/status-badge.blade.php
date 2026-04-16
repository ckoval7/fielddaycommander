@props(['status' => 'available'])

@php
    $statusClasses = match($status) {
        'committed' => 'badge-info',
        'delivered' => 'badge-success',
        'returned' => 'badge-neutral',
        'cancelled' => 'badge-error',
        'lost' => 'badge-error',
        'damaged' => 'badge-error',
        default => 'badge-success badge-outline'
    };
    $statusIcon = match($status) {
        'committed' => 'phosphor-clipboard-text',
        'delivered' => 'phosphor-truck',
        'returned' => 'phosphor-check-circle',
        'cancelled' => 'phosphor-x-circle',
        'lost' => 'phosphor-warning',
        'damaged' => 'phosphor-warning',
        default => 'phosphor-check-circle'
    };
@endphp

<x-badge
    :value="ucfirst(str_replace('_', ' ', $status))"
    class="{{ $statusClasses }}"
    :icon="$statusIcon"
/>
