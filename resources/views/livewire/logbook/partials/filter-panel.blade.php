<div x-data="{ open: false }">
    {{-- Filter toggle button + active filter count --}}
    <div class="flex items-center gap-2 mb-2">
        <button
            type="button"
            @click="open = !open"
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
        <div class="p-4 rounded-lg bg-base-200/50 border border-base-300">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                {{-- Band Filter --}}
                <x-choices-offline
                    label="Band"
                    wire:model.live="bandIds"
                    :options="$this->bands"
                    placeholder="All Bands"
                    icon="phosphor-cell-signal-high"
                    searchable
                />

                {{-- Mode Filter --}}
                <x-choices-offline
                    label="Mode"
                    wire:model.live="modeIds"
                    :options="$this->modes"
                    placeholder="All Modes"
                    icon="phosphor-radio"
                    searchable
                />

                {{-- Station Filter --}}
                <x-choices-offline
                    label="Station"
                    wire:model.live="stationIds"
                    :options="$this->stations"
                    placeholder="All Stations"
                    icon="phosphor-house"
                    searchable
                />

                {{-- Operator Filter --}}
                <x-choices-offline
                    label="Operator"
                    wire:model.live="operatorIds"
                    :options="$this->operators"
                    option-label="display_name"
                    placeholder="All Operators"
                    icon="phosphor-user"
                    searchable
                />

                {{-- Section Filter --}}
                <x-choices-offline
                    label="Section"
                    wire:model.live="sectionIds"
                    :options="$this->sections"
                    option-label="display_name"
                    placeholder="All Sections"
                    icon="phosphor-map-trifold"
                    searchable
                />

                {{-- Callsign Search --}}
                <x-input
                    label="Callsign"
                    wire:model.live.debounce.500ms="callsignSearch"
                    placeholder="Search callsign..."
                    icon="phosphor-magnifying-glass"
                    clearable
                />

                {{-- Time From --}}
                <x-flatpickr
                    label="Time From"
                    wire:model.live="timeFrom"
                    icon="phosphor-calendar"
                />

                {{-- Time To --}}
                <x-flatpickr
                    label="Time To"
                    wire:model.live="timeTo"
                    icon="phosphor-calendar"
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
        </div>
    </template>
</div>
