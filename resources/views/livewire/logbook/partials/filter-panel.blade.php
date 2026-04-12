{{-- Filter Panel Component --}}
<x-card class="shadow-md">
    <div x-data="{ showFilters: true }">
        {{-- Header with Toggle --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <h3 class="text-lg font-semibold">Filters</h3>
            <div class="flex items-center gap-2">
                <button
                    @click="showFilters = !showFilters"
                    class="btn btn-sm btn-ghost min-h-[2.75rem] sm:min-h-[1.75rem]"
                    type="button"
                >
                    <x-icon x-show="!showFilters" name="o-chevron-down" class="w-5 h-5" />
                    <x-icon x-show="showFilters" name="o-chevron-up" class="w-5 h-5" />
                    <span class="ml-1" x-text="showFilters ? 'Hide Filters' : 'Show Filters'"></span>
                </button>
                <button
                    wire:click="resetFilters"
                    class="btn btn-sm btn-outline min-h-[2.75rem] sm:min-h-[1.75rem]"
                    type="button"
                >
                    <x-icon name="o-x-mark" class="w-4 h-4" />
                    <span class="hidden sm:inline ml-1">Reset Filters</span>
                    <span class="sm:hidden ml-1">Reset</span>
                </button>
            </div>
        </div>

        {{-- Filter Controls --}}
        <div x-show="showFilters" x-collapse>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                {{-- Band Filter --}}
                <x-choices-offline
                    label="Band"
                    wire:model.live="bandIds"
                    :options="$this->bands"
                    placeholder="All Bands"
                    icon="o-signal"
                    searchable
                />

                {{-- Mode Filter --}}
                <x-choices-offline
                    label="Mode"
                    wire:model.live="modeIds"
                    :options="$this->modes"
                    placeholder="All Modes"
                    icon="o-radio"
                    searchable
                />

                {{-- Station Filter --}}
                <x-choices-offline
                    label="Station"
                    wire:model.live="stationIds"
                    :options="$this->stations"
                    placeholder="All Stations"
                    icon="o-home"
                    searchable
                />

                {{-- Operator Filter --}}
                <x-choices-offline
                    label="Operator"
                    wire:model.live="operatorIds"
                    :options="$this->operators"
                    option-label="display_name"
                    placeholder="All Operators"
                    icon="o-user"
                    searchable
                />

                {{-- Section Filter --}}
                <x-choices-offline
                    label="Section"
                    wire:model.live="sectionIds"
                    :options="$this->sections"
                    option-label="display_name"
                    placeholder="All Sections"
                    icon="o-map"
                    searchable
                />

                {{-- Callsign Search --}}
                <x-input
                    label="Callsign"
                    wire:model.live.debounce.500ms="callsignSearch"
                    placeholder="Search callsign..."
                    icon="o-magnifying-glass"
                    clearable
                />

                {{-- Time From --}}
                <x-flatpickr
                    label="Time From"
                    wire:model.live="timeFrom"
                    icon="o-calendar"
                />

                {{-- Time To --}}
                <x-flatpickr
                    label="Time To"
                    wire:model.live="timeTo"
                    icon="o-calendar"
                />

                {{-- Duplicate Filter --}}
                <fieldset class="flex flex-col gap-2">
                    <legend class="text-sm font-medium text-base-content/70">Duplicate Status</legend>
                    <div class="flex flex-col gap-2">
                        <x-radio
                            wire:model.live="showDuplicates"
                            :options="[
                                ['id' => null, 'name' => 'All Contacts'],
                                ['id' => 'exclude', 'name' => 'Exclude Duplicates'],
                                ['id' => 'only', 'name' => 'Duplicates Only']
                            ]"
                        />
                    </div>
                </fieldset>

                {{-- Transcribed Filter --}}
                <fieldset class="flex flex-col gap-2">
                    <legend class="text-sm font-medium text-base-content/70">Transcribed Status</legend>
                    <div class="flex flex-col gap-2">
                        <x-radio
                            wire:model.live="showTranscribed"
                            :options="[
                                ['id' => null, 'name' => 'All Contacts'],
                                ['id' => 'only', 'name' => 'Transcribed Only']
                            ]"
                        />
                    </div>
                </fieldset>

                {{-- GOTA Filter --}}
                <fieldset class="flex flex-col gap-2">
                    <legend class="text-sm font-medium text-base-content/70">GOTA Status</legend>
                    <div class="flex flex-col gap-2">
                        <x-radio
                            wire:model.live="showGota"
                            :options="[
                                ['id' => null, 'name' => 'All Contacts'],
                                ['id' => 'only', 'name' => 'GOTA Only'],
                                ['id' => 'exclude', 'name' => 'Exclude GOTA']
                            ]"
                        />
                    </div>
                </fieldset>

                {{-- Deleted Status Filter (edit-contacts only) --}}
                @can('edit-contacts')
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium text-base-content/70">Deleted Status</legend>
                        <div class="flex flex-col gap-2">
                            <x-radio
                                wire:model.live="showDeleted"
                                :options="[
                                    ['id' => null, 'name' => 'Active Only'],
                                    ['id' => 'include', 'name' => 'Include Deleted'],
                                    ['id' => 'only', 'name' => 'Deleted Only']
                                ]"
                            />
                        </div>
                    </fieldset>
                @endcan

            </div>

            {{-- Active Filters Summary --}}
            @php
                $activeFilters = collect([
                    'Band' => count($bandIds) > 0 ? 'Active' : null,
                    'Mode' => count($modeIds) > 0 ? 'Active' : null,
                    'Station' => count($stationIds) > 0 ? 'Active' : null,
                    'Operator' => count($operatorIds) > 0 ? 'Active' : null,
                    'Section' => count($sectionIds) > 0 ? 'Active' : null,
                    'Callsign' => $callsignSearch,
                    'Time Range' => ($timeFrom || $timeTo) ? 'Active' : null,
                    'Duplicates' => $showDuplicates,
                    'Transcribed' => $showTranscribed,
                    'GOTA' => $showGota,
                    'Deleted' => $showDeleted,
                ])->filter()->count();
            @endphp

            @if($activeFilters > 0)
                <div class="mt-4 pt-4 border-t border-base-300">
                    <div class="flex items-center gap-2 text-sm text-base-content/70">
                        <x-icon name="o-funnel" class="w-4 h-4" />
                        <span>{{ $activeFilters }} {{ Str::plural('filter', $activeFilters) }} active</span>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-card>
