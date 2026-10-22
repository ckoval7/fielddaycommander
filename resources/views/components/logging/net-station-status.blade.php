{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@props(['stations', 'net'])

@php
    /**
     * The same age wording as station-last-heard.js, for the first paint.
     */
    $ago = function (?\Carbon\CarbonInterface $at): string {
        if ($at === null) {
            return 'Not heard yet';
        }

        $seconds = max(0, (int) $at->diffInSeconds(appNow(), true));

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60).'m ago',
            $seconds < 86400 => intdiv($seconds, 3600).'h ago',
            default => intdiv($seconds, 86400).'d ago',
        };
    };
    $overdueCount = $stations->where('overdue', true)->count();
@endphp

<details
    class="group bg-base-100 rounded-lg border border-base-300"
    x-data="stationLastHeard"
    x-init="$el.open = window.matchMedia('(min-width: 1024px)').matches"
    wire:ignore.self
    data-station-status
>
    <summary class="flex flex-wrap items-center gap-x-3 gap-y-1 cursor-pointer select-none px-3 sm:px-4 py-2 min-h-11 touch-manipulation">
        <x-icon name="phosphor-caret-right" class="w-4 h-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" />
        <span class="font-semibold">Stations</span>
        <span class="text-sm text-base-content/60">{{ $stations->count() }}</span>
        <span
            class="badge badge-error gap-1"
            x-show="overdueCount() > 0"
            @if($overdueCount === 0) style="display: none" @endif
        >
            <x-icon name="phosphor-warning" class="w-3.5 h-3.5" />
            <span x-text="overdueCount() + ' overdue'">{{ $overdueCount }} overdue</span>
        </span>
        @if($net->overdue_after_minutes)
            <span class="text-xs text-base-content/60 ml-auto">Overdue after {{ $net->overdue_after_minutes }} min</span>
        @endif
    </summary>

    <ul class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2 px-3 pb-3 sm:px-4 sm:pb-4">
        @foreach($stations as $row)
            @php
                $heardIso = $row['last_heard_at']?->toIso8601String();
                $overdueIso = $row['overdue_at']?->toIso8601String();
            @endphp
            <li
                wire:key="station-status-{{ $row['station']->id }}"
                @class(['rounded-md border border-base-300 p-2 text-sm min-w-0', '!border-error bg-error/10' => $row['overdue']])
                data-overdue-at="{{ $overdueIso }}"
                :class="{ '!border-error bg-error/10': isOverdue($el.dataset.overdueAt) }"
                data-station-row
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="font-semibold break-words min-w-0">{{ $row['name'] }}</span>
                    @if($row['unavailable'])
                        <span class="badge badge-ghost badge-sm shrink-0">Out of service</span>
                    @elseif($overdueIso)
                        <span
                            class="badge badge-error badge-sm gap-1 shrink-0"
                            x-show="isOverdue($el.closest('[data-station-row]').dataset.overdueAt)"
                            @unless($row['overdue']) style="display: none" @endunless
                        >
                            <x-icon name="phosphor-warning" class="w-3 h-3" />
                            Overdue
                        </span>
                    @endif
                </div>
                @if($row['location'])
                    <div class="flex items-start gap-1 text-base-content/70 break-words">
                        <x-icon name="phosphor-map-pin" class="w-4 h-4 shrink-0 mt-0.5" />
                        <span class="min-w-0">{{ $row['location'] }}</span>
                    </div>
                @endif
                <div class="flex flex-wrap justify-between gap-x-3 text-base-content/70">
                    <span>{{ $row['operator'] ?? 'No operator on duty' }}</span>
                    <span>
                        <span class="sr-only">Last heard</span>
                        <span data-heard-at="{{ $heardIso }}" x-text="ago($el.dataset.heardAt)">{{ $ago($row['last_heard_at']) }}</span>
                    </span>
                </div>
            </li>
        @endforeach
    </ul>
    <span hidden data-server-now="{{ appNow()->toIso8601String() }}"></span>
</details>
