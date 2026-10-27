{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Dashboard widget "Updated …" footer.

Renders the server-side relative time, then keeps it ageing client-side
(see resources/js/components/updated-ago.js) so a widget that stops
refreshing visibly goes stale instead of saying "just now" forever.
--}}
@props(['at' => null])

@php
    $updatedAt = $at ? \Illuminate\Support\Carbon::parse($at) : null;
@endphp

<div
    {{ $attributes->merge(['class' => 'text-xs text-base-content/70 text-right mt-auto pt-2']) }}
    x-data="updatedAgo"
    data-updated-at="{{ $updatedAt?->toIso8601String() }}"
    data-server-now="{{ appNow()->toIso8601String() }}"
>Updated <span x-text="label">{{ formatTimeAgo($updatedAt) }}</span></div>
