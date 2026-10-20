{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Net Priority Badge Component

Badge for a priority or emergency net log entry. Renders nothing for
routine traffic.

Props:
- $priority: NetPriority - The entry's priority
- $size: string - daisyUI badge size class
--}}

@props(['priority', 'size' => 'badge-xs'])

@if($priority->tone())
    <span class="badge badge-{{ $priority->tone() }} {{ $size }} gap-1 mr-1 align-middle">
        <x-icon :name="$priority->icon()" class="w-3 h-3" />{{ $priority->label() }}
    </span>
@endif
