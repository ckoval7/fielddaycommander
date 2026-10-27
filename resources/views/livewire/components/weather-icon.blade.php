{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div wire:poll.900s="loadWeatherData">
    @php
        $shouldShow = $hasData || $canManageWeather;
        $isGrayedOut = ! $hasData && $canManageWeather;
    @endphp

    @if($shouldShow)
        <a
            wire:navigate
            href="{{ route('weather.index') }}"
            class="flex items-center gap-1.5 btn btn-ghost btn-md lg:btn-sm px-2 min-w-11 {{ $isGrayedOut ? 'opacity-70' : '' }}"
            title="View Weather"
        >
            {{-- Named by its visible text (temperature, Manual) so voice control can target it --}}
            <span class="sr-only">Weather</span>
            <x-icon name="{{ $this->iconName() }}" class="w-6 h-6 lg:w-5 lg:h-5" />

            @if($hasData && $temperature !== null)
                <span class="text-sm font-medium tabular-nums">{{ round($temperature) }}°</span>
            @endif

            @if($isManual && $hasData)
                <span class="badge badge-warning badge-xs font-bold">Manual</span>
            @endif

            @if($hasData && $gusts !== null && $gusts >= 25)
                <span class="badge badge-warning badge-xs">
                    <x-icon name="phosphor-lightning-duotone" class="w-3 h-3" />
                </span>
            @endif
        </a>
    @endif
</div>
