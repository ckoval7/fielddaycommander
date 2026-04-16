<div>
<div class="px-3 py-2" x-data="{ open: false }">
    @if($contextEvent)
        {{-- Current event info --}}
        <div class="mb-1">
            <div class="font-semibold text-sm text-base-content truncate" title="{{ $contextEvent->name }}">
                {{ $contextEvent->name }}
            </div>
            <div class="mt-0.5">
                @switch($gracePeriodStatus)
                    @case('active')
                        <span class="badge badge-success badge-sm gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-success-content inline-block"></span>
                            Active
                        </span>
                        @break
                    @case('grace')
                        <span class="badge badge-warning badge-sm gap-1">
                            Grace Period
                        </span>
                        @break
                    @case('setup')
                        <span class="badge badge-secondary badge-sm gap-1">
                            Setup
                        </span>
                        @break
                    @case('upcoming')
                        <span class="badge badge-info badge-sm gap-1">
                            Upcoming
                        </span>
                        @break
                    @case('archived')
                        <span class="badge badge-ghost badge-sm gap-1">
                            Archived
                        </span>
                        @break
                @endswitch
            </div>
        </div>

        {{-- Return to active event button --}}
        @if($isViewingPast)
            <button
                wire:click="returnToActive"
                class="btn btn-xs btn-warning btn-outline w-full mt-1 mb-1"
            >
                <x-icon name="phosphor-arrow-u-up-left" class="w-3 h-3" />
                Return to Active Event
            </button>
        @endif
    @else
        {{-- No event selected --}}
        <div class="mb-1">
            <div class="font-semibold text-sm text-base-content/60">
                No Event Selected
            </div>
        </div>
    @endif

    {{-- Dropdown toggle --}}
    <button
        @click="open = !open"
        class="btn btn-xs btn-ghost w-full justify-between mt-1"
    >
        <span>{{ $contextEvent ? 'Change Event' : 'Select Event' }}</span>
        <x-icon name="phosphor-caret-down" class="w-3 h-3 transition-transform" ::class="open && 'rotate-180'" />
    </button>

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
