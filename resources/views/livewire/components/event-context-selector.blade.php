{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
<div x-data="{ open: false }">
    {{-- Current event header doubles as the dropdown toggle --}}
    <button
        type="button"
        @click="open = !open"
        class="flex items-center gap-2 w-full rounded-md px-2 py-1.5 text-left hover:bg-base-200 transition-colors"
        :aria-expanded="open"
        title="{{ $contextEvent ? 'Change event' : 'Select event' }}"
    >
        @if($contextEvent)
            <span class="font-semibold text-sm text-base-content truncate min-w-0" title="{{ $contextEvent->name }}">
                {{ $contextEvent->name }}
            </span>
            @switch($gracePeriodStatus)
                @case('active')
                    <span class="badge badge-success badge-xs gap-1 shrink-0">
                        <span class="w-1.5 h-1.5 rounded-full bg-success-content inline-block"></span>
                        Active
                    </span>
                    @break
                @case('grace')
                    <span class="badge badge-warning badge-xs shrink-0">Grace Period</span>
                    @break
                @case('setup')
                    <span class="badge badge-secondary badge-xs shrink-0">Setup</span>
                    @break
                @case('upcoming')
                    <span class="badge badge-info badge-xs shrink-0">Upcoming</span>
                    @break
                @case('archived')
                    <span class="badge badge-ghost badge-xs shrink-0">Archived</span>
                    @break
            @endswitch
        @else
            <span class="font-semibold text-sm text-base-content/60 truncate">No Event Selected</span>
        @endif
        <x-icon name="phosphor-caret-down" class="w-3 h-3 ml-auto shrink-0 text-base-content/60 transition-transform" ::class="open && 'rotate-180'" />
    </button>

    {{-- Return to active event button --}}
    @if($contextEvent && $isViewingPast)
        <button
            wire:click="returnToActive"
            class="btn btn-xs btn-warning btn-outline w-full mt-1"
        >
            <x-icon name="phosphor-arrow-u-up-left" class="w-3 h-3" />
            Return to Active Event
        </button>
    @endif

    {{-- Event list dropdown --}}
    <div x-show="open" x-cloak x-transition class="mt-1 max-h-64 overflow-y-auto">
        @php
            $groupLabels = [
                'active' => 'Active',
                'grace' => 'Grace Period',
                'setup' => 'Setup',
                'upcoming' => 'Upcoming',
                'archived' => 'Archived',
            ];
            $groupBadgeClasses = [
                'active' => 'badge-success',
                'grace' => 'badge-warning',
                'setup' => 'badge-secondary',
                'upcoming' => 'badge-info',
                'archived' => 'badge-ghost',
            ];
        @endphp

        @foreach($groupLabels as $groupKey => $groupLabel)
            @if($grouped[$groupKey]->isNotEmpty())
                <div class="text-xs font-bold uppercase tracking-wider text-base-content/50 px-2 pt-2 pb-1">
                    {{ $groupLabel }}
                </div>
                @foreach($grouped[$groupKey] as $event)
                    <button
                        wire:click="switchEvent({{ $event->id }})"
                        @class([
                            'btn btn-xs btn-ghost w-full justify-start text-left truncate',
                            'btn-active' => $contextEvent && $contextEvent->id === $event->id,
                        ])
                    >
                        <span class="truncate">{{ $event->name }}</span>
                        @if($event->year)
                            <span class="badge {{ $groupBadgeClasses[$groupKey] }} badge-xs ml-auto shrink-0">{{ $event->year }}</span>
                        @endif
                    </button>
                @endforeach
            @endif
        @endforeach

        @if($grouped['active']->isEmpty() && $grouped['grace']->isEmpty() && $grouped['setup']->isEmpty() && $grouped['upcoming']->isEmpty() && $grouped['archived']->isEmpty())
            <div class="text-xs text-base-content/50 px-2 py-2 text-center">
                No events found
            </div>
        @endif
    </div>
</div>
</div>
