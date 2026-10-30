{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Countdown markup shared by the live component and its lazy-load placeholder,
     so the placeholder takes the same space and the header doesn't jump when the
     component arrives. $live is false in the placeholder, which has no Alpine scope. --}}
@php($live ??= true)
@if($event)
    {{-- Mobile layout — compact Row 2 status bar --}}
    <div class="lg:hidden flex items-center justify-between gap-3">
        <div class="min-w-0 flex-1 flex flex-col gap-0.5">
            <span class="badge {{ $badgeClass }} badge-sm text-[11px] font-bold uppercase tracking-wide flex items-center gap-1 w-fit">
                @if($state === 'setup')
                    <x-icon name="phosphor-wrench" class="w-3 h-3" />
                @elseif($state === 'upcoming')
                    <x-icon name="phosphor-calendar-dots" class="w-3 h-3" />
                @elseif($state === 'active')
                    <x-icon name="phosphor-play-circle" class="w-3 h-3" />
                @elseif($state === 'ended')
                    <x-icon name="phosphor-seal-check" class="w-3 h-3" />
                @endif
                {{ $state === 'active' ? 'LIVE' : strtoupper($state) }}
            </span>
            <span class="text-sm font-semibold text-base-content truncate">
                {{ $event->name }}
            </span>
        </div>
        <div class="text-right shrink-0 flex flex-col items-end">
            <span class="text-[10px] uppercase tracking-[0.8px] font-semibold text-base-content/70">
                {{ $state === 'ended' ? 'Ended' : ($state === 'active' ? 'Ends in' : 'Starts in') }}
            </span>
            <span class="font-mono font-bold text-base leading-tight {{ $textClass }}" aria-hidden="true" @if($live) x-text="countdown" @endif>
                {{ $formattedCountdown }}{{ $state === 'ended' ? ' ago' : '' }}
            </span><span class="sr-only" @if($live) x-text="window.fdcSpokenDuration?.(countdown) ?? countdown" @endif>{{ spokenDuration($formattedCountdown.($state === 'ended' ? ' ago' : '')) }}</span>
        </div>
    </div>

    {{-- Desktop layout — full header timer bar --}}
    <div class="hidden lg:flex flex-row items-center justify-center min-[1400px]:items-baseline gap-4">
        {{-- Event Badge and Name --}}
        <div class="flex items-center gap-3">
            <span class="badge {{ $badgeClass }} badge-lg text-base font-bold flex items-center gap-2 max-[1399px]:hidden">
                @if($state === 'setup')
                    <x-icon name="phosphor-wrench" class="w-4 h-4" />
                @elseif($state === 'upcoming')
                    <x-icon name="phosphor-calendar-dots" class="w-4 h-4" />
                @elseif($state === 'active')
                    <x-icon name="phosphor-play-circle" class="w-4 h-4" />
                @elseif($state === 'ended')
                    <x-icon name="phosphor-seal-check" class="w-4 h-4" />
                @endif
                {{ $state === 'active' ? 'LIVE' : strtoupper($state) }}
            </span>
            {{-- The brand beside this already names the event, so the name only
                 shows once the header has room for it on one line. --}}
            <span class="text-2xl font-bold whitespace-nowrap max-xl:hidden {{ $textClass }}">
                {{ $event->name }}
            </span>
        </div>

        <span class="text-2xl text-base-content/30 max-xl:hidden" aria-hidden="true">|</span>

        {{-- Countdown Label and Time --}}
        <div class="flex items-center gap-2 whitespace-nowrap">
            <span class="text-lg font-semibold">
                {{ $label }}{{ $state !== 'ended' ? ':' : '' }}
            </span>
            <span class="text-2xl font-mono font-bold {{ $textClass }}" aria-hidden="true" @if($live) x-text="countdown" @endif>
                {{ $formattedCountdown }}{{ $state === 'ended' ? ' ago' : '' }}
            </span><span class="sr-only" @if($live) x-text="window.fdcSpokenDuration?.(countdown) ?? countdown" @endif>{{ spokenDuration($formattedCountdown.($state === 'ended' ? ' ago' : '')) }}</span>
        </div>

        <span class="text-2xl text-base-content/30" aria-hidden="true">|</span>

        {{-- Clocks --}}
        <div class="flex items-center gap-4 max-[1399px]:flex-col max-[1399px]:items-start max-[1399px]:gap-1 text-lg">
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">{{ $timezoneLabel }}:</span>
                <span class="font-mono font-bold text-2xl" @if($live) x-text="localTime" @endif>{{ appNow()->timezone($timezone)->format('H:i:s') }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">UTC:</span>
                <span class="font-mono font-bold text-2xl" @if($live) x-text="utcTime" @endif>{{ appNow()->utc()->format('H:i:s') }}</span>
            </div>
        </div>
    </div>
@endif
