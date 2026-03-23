@php
    $showSearch = $showSearch ?? true;
    $showTimeFilter = $showTimeFilter ?? true;
    $showStatusFilter = $showStatusFilter ?? true;
    $showAvailability = $showAvailability ?? true;
    $statuses = $statuses ?? [];
@endphp

<div x-data="{ open: false }" class="mb-6">
    {{-- Filter toggle button + active filter count --}}
    <div class="flex items-center gap-2 mb-2">
        <button
            type="button"
            @click="open = !open"
            class="btn btn-sm btn-outline gap-2"
        >
            <x-icon name="o-funnel" class="w-4 h-4" />
            Filters
            @if($this->activeFilterCount > 0)
                <span class="badge badge-primary badge-sm">{{ $this->activeFilterCount }}</span>
            @endif
            <x-icon x-show="!open" name="o-chevron-down" class="w-3 h-3" />
            <x-icon x-show="open" name="o-chevron-up" class="w-3 h-3" />
        </button>

        @if($this->activeFilterCount > 0)
            <button
                type="button"
                wire:click="resetFilters"
                class="btn btn-ghost btn-xs text-error"
            >
                Clear all
            </button>
        @endif
    </div>

    {{-- Active filter pills (visible when collapsed) --}}
    @if($this->activeFilterCount > 0)
        <div x-show="!open" class="flex flex-wrap gap-1 mb-3">
            @foreach($this->activeFilterPills as $pill)
                <span class="badge badge-outline badge-sm gap-1">
                    {{ $pill['label'] }}
                    <button
                        type="button"
                        wire:click="removeFilter('{{ $pill['key'] }}')"
                        class="hover:text-error"
                    >&times;</button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- Expanded filter controls --}}
    <template x-if="open">
    <div
        class="p-4 rounded-lg bg-base-200/50 border border-base-300"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Search --}}
            @if($showSearch)
                <div>
                    <label class="label label-text text-xs font-semibold">Search by name / call sign</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search..."
                        class="input input-bordered input-sm w-full"
                    />
                </div>
            @endif

            {{-- Role dropdown --}}
            <div>
                <label class="label label-text text-xs font-semibold">Role</label>
                <select wire:model.live="role" class="select select-bordered select-sm w-full">
                    <option value="">All Roles</option>
                    @foreach($this->filterRoles as $filterRole)
                        <option value="{{ $filterRole->id }}">{{ $filterRole->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Status dropdown --}}
            @if($showStatusFilter && count($statuses) > 0)
                <div>
                    <label class="label label-text text-xs font-semibold">Status</label>
                    <select wire:model.live="status" class="select select-bordered select-sm w-full">
                        <option value="">All Statuses</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- Availability dropdown --}}
            @if($showAvailability)
                <div>
                    <label class="label label-text text-xs font-semibold">Availability</label>
                    <select wire:model.live="availability" class="select select-bordered select-sm w-full">
                        <option value="">All</option>
                        <option value="unfilled">Unfilled Only</option>
                        <option value="full">Full Only</option>
                    </select>
                </div>
            @endif

            {{-- Time filter --}}
            @if($showTimeFilter)
                <div>
                    <label class="label label-text text-xs font-semibold">Time</label>
                    <select wire:model.live="timeFilter" class="select select-bordered select-sm w-full">
                        <option value="">All Times</option>
                        <option value="current">Current</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="past">Past</option>
                    </select>
                </div>
            @endif

            {{-- Sort --}}
            <div>
                <label class="label label-text text-xs font-semibold">Sort by</label>
                <div class="flex gap-1">
                    <select wire:model.live="sortBy" class="select select-bordered select-sm flex-1">
                        <option value="time">Time</option>
                        <option value="role">Role</option>
                        @if($showAvailability)
                            <option value="fill">Fill %</option>
                        @endif
                    </select>
                    <button
                        type="button"
                        wire:click="$set('sortDir', '{{ $sortDir === 'asc' ? 'desc' : 'asc' }}')"
                        class="btn btn-sm btn-outline"
                        title="{{ $sortDir === 'asc' ? 'Ascending' : 'Descending' }}"
                    >
                        @if($sortDir === 'asc')
                            <x-icon name="o-bars-arrow-up" class="w-4 h-4" />
                        @else
                            <x-icon name="o-bars-arrow-down" class="w-4 h-4" />
                        @endif
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>
