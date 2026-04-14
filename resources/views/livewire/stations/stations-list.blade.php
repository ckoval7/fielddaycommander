<div class="space-y-6">
    {{-- Header --}}
    <x-header title="Stations" subtitle="Manage field day stations and equipment assignments" separator progress-indicator>
        <x-slot:actions>
            @can('create', \App\Models\Station::class)
                <x-button
                    label="Clone from Event"
                    icon="o-arrow-path"
                    class="btn-outline"
                    wire:click="$dispatch('open-clone-modal')"
                    responsive
                />
                <x-button label="Add Station" icon="o-plus" class="btn-primary" link="{{ route('stations.create') }}" wire:navigate responsive />
            @endcan
        </x-slot:actions>
    </x-header>

    {{-- Station Clone Component --}}
    @can('create', \App\Models\Station::class)
        <livewire:stations.station-clone @stations-cloned="$refresh" />
    @endcan

    {{-- Event Filter --}}
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="w-full md:w-64">
            <x-select
                label="Event"
                wire:model.live="eventFilter"
                :options="$events->map(fn($event) => [
                    'value' => $event->id,
                    'label' => $event->name . ' (' . $event->start_time?->format('Y') . ')'
                ])"
                option-value="value"
                option-label="label"
                placeholder="Select an event..."
            />
        </div>

        @if($eventFilter)
            {{-- Quick Stats --}}
            <div class="w-full md:w-auto overflow-x-auto">
                <div class="stats shadow stats-vertical sm:stats-horizontal w-full sm:w-auto">
                    <div class="stat py-3 px-4">
                        <div class="stat-title text-xs">Total Stations</div>
                        <div class="stat-value text-lg sm:text-2xl">{{ $stats['total'] }}</div>
                    </div>
                    <div class="stat py-3 px-4">
                        <div class="stat-title text-xs">Active Now</div>
                        <div class="stat-value text-lg sm:text-2xl text-success">{{ $stats['active'] }}</div>
                    </div>
                    @if($stats['idle'] > 0)
                        <div class="stat py-3 px-4">
                            <div class="stat-title text-xs">Idle</div>
                            <div class="stat-value text-lg sm:text-2xl text-warning">{{ $stats['idle'] }}</div>
                        </div>
                    @endif
                    <div class="stat py-3 px-4">
                        <div class="stat-title text-xs">Equipment Items</div>
                        <div class="stat-value text-lg sm:text-2xl">{{ $stats['equipment_count'] }}</div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if(!$eventFilter)
        {{-- No Event Selected --}}
        <x-card shadow>
            <div class="text-center py-12 text-base-content/60">
                <x-icon name="o-radio" class="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p class="text-lg font-semibold mb-2">No Event Selected</p>
                <p class="text-sm">Please select an event to view its stations.</p>
            </div>
        </x-card>
    @elseif($stations->isEmpty())
        {{-- Empty State --}}
        <x-card shadow>
            <div class="text-center py-12 text-base-content/60">
                <x-icon name="o-radio" class="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p class="text-lg font-semibold mb-2">No stations configured for this event</p>
                <p class="text-sm mb-4">Get started by adding your first station.</p>
                @can('create', \App\Models\Station::class)
                    <x-button label="Add Station" icon="o-plus" class="btn-primary" link="{{ route('stations.create') }}" wire:navigate />
                @endcan
            </div>
        </x-card>
    @else
        {{-- Station Cards Grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            @foreach($stations as $station)
                <x-card wire:key="station-{{ $station->id }}" shadow>
                    {{-- Station Name & Badges --}}
                    <div class="mb-4">
                        <h3 class="text-xl sm:text-2xl font-bold mb-2 break-words">{{ $station->name }}</h3>

                        <div class="flex flex-wrap gap-2">
                            @if($station->is_gota)
                                <x-badge value="GOTA" class="badge-primary badge-sm sm:badge-md" icon="o-academic-cap" />
                            @endif

                            @if($station->is_vhf_only)
                                <x-badge value="VHF-only" class="badge-info badge-sm sm:badge-md" icon="o-signal" />
                            @endif

                            @if($station->is_satellite)
                                <x-badge value="Satellite" class="badge-accent badge-sm sm:badge-md" icon="o-globe-alt" />
                            @endif

                            @if($station->operatingStatus() === 'occupied')
                                <x-badge value="Active" class="badge-success badge-sm sm:badge-md" icon="o-bolt" />
                            @elseif($station->operatingStatus() === 'idle')
                                <x-badge value="Idle" class="badge-warning badge-sm sm:badge-md" icon="o-clock" />
                            @endif
                        </div>
                    </div>

                    {{-- Primary Radio --}}
                    @if($station->primaryRadio)
                        <div class="mb-3 pb-3 border-b border-base-300">
                            <div class="text-xs text-base-content/60 mb-1">Primary Radio</div>
                            <div class="font-semibold text-sm sm:text-base">{{ $station->primaryRadio->make }} {{ $station->primaryRadio->model }}</div>
                            @if($station->max_power_watts)
                                <div class="text-xs sm:text-sm text-base-content/70">{{ $station->max_power_watts }}W</div>
                            @endif
                        </div>
                    @else
                        <div class="mb-3 pb-3 border-b border-base-300">
                            <div class="text-xs text-base-content/60 mb-1">Primary Radio</div>
                            <div class="text-xs sm:text-sm italic text-base-content/50">Not assigned</div>
                        </div>
                    @endif

                    {{-- Equipment Count --}}
                    <div class="mb-3">
                        <div class="flex items-center gap-2">
                            <x-icon name="o-wrench-screwdriver" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                            <span class="text-xs sm:text-sm">
                                <span class="font-semibold">{{ $station->additional_equipment_count }}</span>
                                {{ Str::plural('item', $station->additional_equipment_count) }} assigned
                            </span>
                        </div>
                    </div>

                    {{-- Power Source --}}
                    @if($station->power_source || $station->power_source_description)
                        <div class="mb-4">
                            <div class="text-xs text-base-content/60 mb-1">Power Source</div>
                            @if($station->power_source)
                                <span class="badge badge-sm {{ $station->power_source->isNaturalPower() ? 'badge-success' : ($station->power_source->isEmergencyPower() ? 'badge-info' : 'badge-warning') }}">
                                    {{ $station->power_source->label() }}
                                </span>
                            @endif
                            @if($station->power_source_description)
                                <p class="text-xs sm:text-sm line-clamp-2 mt-1">{{ $station->power_source_description }}</p>
                            @endif
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="flex flex-col sm:flex-row gap-2 pt-3 border-t border-base-300">
                        @can('update', $station)
                            @if($station->is_active)
                                <x-button
                                    label="End Sessions"
                                    icon="o-stop"
                                    class="btn-sm btn-warning flex-1 min-h-[2.75rem] sm:min-h-[1.75rem]"
                                    wire:click="endSessions({{ $station->id }})"
                                    wire:confirm="End all active operating sessions for '{{ $station->name }}'?"
                                    spinner
                                />
                            @endif
                            <x-button
                                label="Edit"
                                icon="o-pencil"
                                class="btn-sm btn-outline flex-1 min-h-[2.75rem] sm:min-h-[1.75rem]"
                                link="{{ route('stations.edit', $station) }}"
                                wire:navigate
                            />
                        @endcan

                        @can('delete', $station)
                            <x-button
                                icon="o-trash"
                                class="btn-sm btn-ghost text-error min-h-[2.75rem] sm:min-h-[1.75rem]"
                                wire:click="deleteStation({{ $station->id }})"
                                wire:confirm="Are you sure you want to delete '{{ $station->name }}'? {{ $station->contacts()->exists() ? 'This station has contacts and will be archived (soft deleted).' : 'This station will be permanently deleted.' }}"
                                spinner
                            />
                        @endcan
                    </div>
                </x-card>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($stations->hasPages())
            <div class="mt-6">
                {{ $stations->links() }}
            </div>
        @endif
    @endif
</div>
