{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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
            :aria-expanded="open.toString()"
            aria-controls="schedule-filters"
            class="btn btn-sm btn-outline gap-2"
        >
            <x-icon name="phosphor-funnel" class="w-4 h-4" />
            Filters
            @if($this->activeFilterCount > 0)
                <span class="badge badge-primary badge-sm">{{ $this->activeFilterCount }}</span>
            @endif
            <x-icon x-show="!open" name="phosphor-caret-down" class="w-3 h-3" />
            <x-icon x-show="open" name="phosphor-caret-up" class="w-3 h-3" />
        </button>

        @if($this->activeFilterCount > 0)
            <button
                type="button"
                wire:click="resetFilters"
                class="btn btn-ghost btn-xs text-error"
            >
                Reset filters and sort
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
                        class="hover:text-error pointer-coarse:min-h-11 pointer-coarse:min-w-11"
                    >&times;</button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- Expanded filter controls --}}
    <template x-if="open">
    <div id="schedule-filters"
        class="p-3 sm:p-4 rounded-lg bg-base-200/50 border border-base-300"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Search --}}
            @if($showSearch)
                <div>
                    <label for="schedule-search" class="block py-1 text-xs font-semibold">Search by name or call sign</label>
                    <input
                        id="schedule-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search..."
                        class="input input-sm w-full"
                    />
                </div>
            @endif

            {{-- Role dropdown --}}
            <div>
                <label for="schedule-role" class="block py-1 text-xs font-semibold">Role</label>
                <label class="select select-sm w-full">
                    <select id="schedule-role" wire:model.live="role">
                        <option value="">All Roles</option>
                        @foreach($this->filterRoles as $filterRole)
                            <option value="{{ $filterRole->id }}">{{ $filterRole->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            {{-- Status dropdown --}}
            @if($showStatusFilter && count($statuses) > 0)
                <div>
                    <label for="schedule-status" class="block py-1 text-xs font-semibold">Status</label>
                    <label class="select select-sm w-full">
                        <select id="schedule-status" wire:model.live="status">
                            <option value="">All Statuses</option>
                            @foreach($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            @endif

            {{-- Availability dropdown --}}
            @if($showAvailability)
                <div>
                    <label for="schedule-availability" class="block py-1 text-xs font-semibold">Availability</label>
                    <label class="select select-sm w-full">
                        <select id="schedule-availability" wire:model.live="availability">
                            <option value="">All</option>
                            <option value="unfilled">Unfilled Only</option>
                            <option value="full">Full Only</option>
                        </select>
                    </label>
                </div>
            @endif

            {{-- Time filter --}}
            @if($showTimeFilter)
                <div>
                    <label for="schedule-time" class="block py-1 text-xs font-semibold">Time</label>
                    <label class="select select-sm w-full">
                        <select id="schedule-time" wire:model.live="timeFilter">
                            <option value="">Current &amp; Upcoming</option>
                            <option value="current">Current</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="past">Past</option>
                            <option value="all">All</option>
                        </select>
                    </label>
                </div>
            @endif

            {{-- Sort --}}
            <div>
                <label for="schedule-sort" class="block py-1 text-xs font-semibold">Sort by</label>
                <div class="flex gap-1">
                    <label class="select select-sm flex-1">
                        <select id="schedule-sort" wire:model.live="sortBy">
                            <option value="time">Time</option>
                            <option value="role">Role</option>
                            @if($showAvailability)
                                <option value="fill">Filled slots (%)</option>
                            @endif
                        </select>
                    </label>
                    <button
                        type="button"
                        wire:click="$set('sortDir', '{{ $sortDir === 'asc' ? 'desc' : 'asc' }}')"
                        class="btn btn-sm btn-outline"
                        title="{{ $sortDir === 'asc' ? 'Sort descending' : 'Sort ascending' }}"
                    >
                        <span class="sr-only">{{ $sortDir === 'asc' ? 'Sort descending' : 'Sort ascending' }}</span>
                        @if($sortDir === 'asc')
                            <x-icon name="phosphor-sort-ascending" class="w-4 h-4" />
                        @else
                            <x-icon name="phosphor-sort-descending" class="w-4 h-4" />
                        @endif
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>
