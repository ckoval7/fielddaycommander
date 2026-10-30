{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- "pts" on screen, "points" to screen readers, which spell out "P T S".
     The empty PHP block at the end swallows the file's final newline, since callers put
     punctuation right after the unit. --}}
@props(['count' => null])
@php
    $singular = $count !== null && (int) $count === 1;
@endphp
<span aria-hidden="true">{{ $singular ? 'pt' : 'pts' }}</span><span class="sr-only">{{ $singular ? 'point' : 'points' }}</span>@php @endphp
