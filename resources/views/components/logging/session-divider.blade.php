{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Session Divider Component

Marks where a band/mode switch started a new session in the QSO log: a
labelled rule as a table row (desktop) or a block (mobile card list).

Props:
- $session: OperatingSession - The session that starts here (band and mode loaded)
- $current: bool - Whether this is the session being logged
- $row: bool - Render as a table row instead of a card-list block
--}}

@props(['session', 'current' => false, 'row' => false])

@php
    $label = trim(($session->band?->name ?? '?').' '.($session->mode?->name ?? '?'));
    $time = $session->start_time?->format('H:i');
@endphp

@if($row)
    <tr data-session-divider aria-hidden="true" class="hover:bg-transparent">
        <td colspan="6" class="py-1.5">
            <div @class(['flex items-center gap-3 text-xs font-mono', 'text-base-content/50' => ! $current, 'text-primary font-semibold' => $current])>
                <span class="flex-1 border-t border-current opacity-40"></span>
                <span>{{ $label }}{{ $time ? ' · '.$time : '' }}</span>
                <span class="flex-1 border-t border-current opacity-40"></span>
            </div>
        </td>
    </tr>
@else
    <div data-session-divider aria-hidden="true" @class(['flex items-center gap-3 py-1 text-xs font-mono', 'text-base-content/50' => ! $current, 'text-primary font-semibold' => $current])>
        <span class="flex-1 border-t border-current opacity-40"></span>
        <span>{{ $label }}{{ $time ? ' · '.$time : '' }}</span>
        <span class="flex-1 border-t border-current opacity-40"></span>
    </div>
@endif
