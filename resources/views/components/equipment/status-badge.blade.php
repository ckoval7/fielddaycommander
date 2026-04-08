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
        'committed' => 'o-clipboard-document-list',
        'delivered' => 'o-truck',
        'returned' => 'o-check-circle',
        'cancelled' => 'o-x-circle',
        'lost' => 'o-exclamation-triangle',
        'damaged' => 'o-exclamation-triangle',
        default => 'o-check-circle'
    };
@endphp

<x-badge
    :value="ucfirst(str_replace('_', ' ', $status))"
    class="{{ $statusClasses }}"
    :icon="$statusIcon"
/>
