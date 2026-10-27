{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    <x-slot:title>Stations</x-slot:title>

    {{-- Header --}}
    <x-header use-h1 title="Stations" subtitle="Manage stations and equipment assignments" separator progress-indicator>
        <x-slot:actions class="flex-wrap max-sm:w-full">
            @can('log-contacts')
                <x-button label="Log Contact" icon="phosphor-pencil-line" class="btn-outline" link="{{ route('logging.station-select') }}" wire:navigate responsive />
            @endcan
            @can('create', \App\Models\Station::class)
                <x-button
                    label="Clone from Event"
                    icon="phosphor-arrow-clockwise"
                    class="btn-outline"
                    wire:click="$dispatch('open-clone-modal')"
                    responsive
                />
                <x-button label="Add Station" icon="phosphor-plus" class="btn-primary" link="{{ route('stations.create') }}" wire:navigate responsive />
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
                    @if($stats['unavailable'] > 0)
                        <div class="stat py-3 px-4">
                            <div class="stat-title text-xs">Unavailable</div>
                            <div class="stat-value text-lg sm:text-2xl text-error">{{ $stats['unavailable'] }}</div>
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
                <x-icon name="phosphor-radio" class="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p class="text-lg font-semibold mb-2">No Event Selected</p>
                <p class="text-sm">Please select an event to view its stations.</p>
            </div>
        </x-card>
    @elseif($stations->isEmpty())
        {{-- Empty State --}}
        <x-card shadow>
            <div class="text-center py-12 text-base-content/60">
                <x-icon name="phosphor-radio" class="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p class="text-lg font-semibold mb-2">No stations configured for this event</p>
                <p class="text-sm mb-4">Get started by adding your first station.</p>
                @can('create', \App\Models\Station::class)
                    <x-button label="Add Station" icon="phosphor-plus" class="btn-primary" link="{{ route('stations.create') }}" wire:navigate />
                @endcan
            </div>
        </x-card>
    @else
        {{-- Station Cards Grid: columns follow the content width, which the sidebar shrinks --}}
        <div class="@container">
            <div class="grid grid-cols-1 @xl:grid-cols-2 @5xl:grid-cols-3 gap-4 sm:gap-6" data-station-grid>
                @foreach($stations as $station)
                    <x-card wire:key="station-{{ $station->id }}" shadow>
                        {{-- Station Name & Badges --}}
                        <div class="mb-4">
                            <h2 class="text-xl sm:text-2xl font-bold mb-2 break-words">{{ $station->name }}</h2>

                            <div class="flex flex-wrap gap-2">
                                @if($station->is_gota)
                                    <x-badge value="GOTA" class="badge-primary badge-sm sm:badge-md" icon="phosphor-graduation-cap" />
                                @endif

                                @if($station->is_vhf_only)
                                    <x-badge value="VHF/UHF Only" class="badge-info badge-sm sm:badge-md" icon="phosphor-cell-signal-high" />
                                @endif

                                @if($station->is_satellite)
                                    <x-badge value="Satellite" class="badge-accent badge-sm sm:badge-md" icon="phosphor-globe" />
                                @endif

                                @php $operatingStatus = $station->operatingStatus(); @endphp
                                @if($operatingStatus === 'occupied')
                                    <x-badge value="Active" class="badge-success badge-sm sm:badge-md" icon="phosphor-lightning" />
                                @elseif($operatingStatus === 'idle')
                                    <x-badge value="Idle" class="badge-warning badge-sm sm:badge-md" icon="phosphor-clock" />
                                @endif
                            </div>
                        </div>

                        {{-- Readiness --}}
                        @if($station->isUnavailable())
                            <div class="mb-3 p-3 rounded-lg bg-error/10 border border-error/30" data-station-unavailable>
                                <div class="flex items-center gap-2 font-semibold text-sm text-error">
                                    <x-icon :name="$station->unavailable_reason->icon()" class="w-4 h-4 flex-shrink-0" />
                                    {{ $station->unavailable_reason->label() }}
                                </div>
                                @if($station->unavailable_note)
                                    <p class="text-xs sm:text-sm mt-1 break-words">{{ $station->unavailable_note }}</p>
                                @endif
                                <p class="text-xs text-base-content/60 mt-1">
                                    Since {{ $station->unavailable_since?->format('M j, H:i') }}
                                    @if($station->unavailableBy)
                                        by {{ $station->unavailableBy->call_sign }}
                                    @endif
                                </p>
                            </div>
                        @endif

                        {{-- Primary Radio --}}
                        @if($station->primaryRadio)
                            <div class="mb-3 pb-3 border-b border-base-300">
                                <div class="text-xs text-base-content/60 mb-1">Primary Radio</div>
                                <div class="font-semibold text-sm sm:text-base">{{ $station->primaryRadio->make }} {{ $station->primaryRadio->model }}</div>
                                @if($station->max_power_watts)
                                    <div class="text-xs sm:text-sm text-base-content/70">{{ spokenWatts($station->max_power_watts) }}</div>
                                @endif
                            </div>
                        @else
                            <div class="mb-3 pb-3 border-b border-base-300">
                                <div class="text-xs text-base-content/60 mb-1">Primary Radio</div>
                                <div class="text-xs sm:text-sm italic text-base-content/70">Not assigned</div>
                            </div>
                        @endif

                        {{-- Equipment Count --}}
                        <div class="mb-3">
                            <div class="flex items-center gap-2">
                                <x-icon name="phosphor-wrench" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
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

                        {{-- Station-mode browsers --}}
                        @can('updateReadiness', $station)
                            <div class="mb-4" data-station-devices>
                                <div class="text-xs text-base-content/60 mb-1">Station-Mode Browsers</div>
                                @forelse($station->devices as $device)
                                    <div class="flex items-center justify-between gap-2 text-xs sm:text-sm py-1" wire:key="station-device-{{ $device->id }}">
                                        <span class="truncate" title="{{ $device->name }}">
                                            Enrolled {{ $device->created_at->diffForHumans() }}
                                            @if($device->last_seen_at)
                                                <span class="text-base-content/60">· seen {{ $device->last_seen_at->diffForHumans() }}</span>
                                            @endif
                                        </span>
                                        <x-button
                                            label="Remove Browser"
                                            class="btn-xs btn-ghost text-error"
                                            wire:click="revokeDevice({{ $device->id }})"
                                            wire:confirm="Remove this browser from station mode? It will no longer be able to start station-mode sessions."
                                            spinner="revokeDevice({{ $device->id }})"
                                        />
                                    </div>
                                @empty
                                    <p class="text-xs text-base-content/60">No browsers are enrolled for this station.</p>
                                @endforelse
                                <x-button
                                    label="Use This Browser for Station Mode"
                                    icon="phosphor-desktop-tower"
                                    class="btn-xs btn-outline mt-2 h-auto min-h-6 py-1 pointer-coarse:min-h-11"
                                    wire:click="enrollThisBrowser({{ $station->id }})"
                                    wire:confirm="Use this browser for station mode at '{{ $station->name }}'? You will be signed out. Operators can then sign in here by callsign to log at this station."
                                    spinner="enrollThisBrowser({{ $station->id }})"
                                />
                            </div>
                        @endcan

                        {{-- Actions --}}
                        <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 pt-3 border-t border-base-300" data-station-actions>
                            @can('updateReadiness', $station)
                                @if($station->isUnavailable())
                                    <x-button
                                        label="Mark Ready"
                                        icon="phosphor-check-circle"
                                        class="btn-sm btn-success flex-1 whitespace-nowrap min-h-[2.75rem] sm:min-h-[1.75rem]"
                                        wire:click="markAvailable({{ $station->id }})"
                                        spinner="markAvailable({{ $station->id }})"
                                    />
                                @else
                                    <x-button
                                        label="Mark Unavailable"
                                        icon="phosphor-prohibit"
                                        class="btn-sm btn-outline btn-error flex-1 whitespace-nowrap min-h-[2.75rem] sm:min-h-[1.75rem]"
                                        wire:click="openUnavailableModal({{ $station->id }})"
                                        spinner="openUnavailableModal({{ $station->id }})"
                                    />
                                @endif
                            @endcan
                            @can('update', $station)
                                @if($station->is_active)
                                    <x-button
                                        label="End Sessions"
                                        icon="phosphor-stop"
                                        class="btn-sm btn-warning flex-1 whitespace-nowrap min-h-[2.75rem] sm:min-h-[1.75rem]"
                                        wire:click="endSessions({{ $station->id }})"
                                        wire:confirm="End all active operating sessions for '{{ $station->name }}'?"
                                        spinner
                                    />
                                @endif
                                <x-button
                                    label="Edit"
                                    icon="phosphor-pencil-simple"
                                    class="btn-sm btn-outline flex-1 whitespace-nowrap min-h-[2.75rem] sm:min-h-[1.75rem]"
                                    link="{{ route('stations.edit', $station) }}"
                                    wire:navigate
                                />
                            @endcan

                            @can('delete', $station)
                                <x-button aria-label="Delete station {{ $station->name }}"
                                    icon="phosphor-trash"
                                    class="btn-sm btn-ghost text-error min-h-[2.75rem] sm:min-h-[1.75rem]"
                                    wire:click="deleteStation({{ $station->id }})"
                                    wire:confirm="Delete '{{ $station->name }}'? {{ $station->contacts()->exists() ? 'This station has logged contacts and will be archived.' : 'This station will be permanently deleted.' }}"
                                    spinner
                                />
                            @endcan
                        </div>
                    </x-card>
                @endforeach
            </div>
        </div>

        {{-- Pagination --}}
        @if($stations->hasPages())
            <div class="mt-6">
                {{ $stations->links() }}
            </div>
        @endif
    @endif

    {{-- Mark Unavailable Modal --}}
    @can('manage-stations')
    <x-modal wire:model="showUnavailableModal" title="Mark Station Unavailable" persistent>
        @php $readinessStation = $this->readinessStation; @endphp
        <div class="space-y-4">
            @if($readinessStation)
                <p class="text-sm">
                    Operators will not be able to start a session on <span class="font-semibold">{{ $readinessStation->name }}</span> until it is marked ready again.
                </p>
                @if($readinessStation->is_active)
                    <x-alert icon="phosphor-warning" class="alert-warning text-sm">
                        This station has an active operating session. It will be ended.
                    </x-alert>
                @endif
            @endif

            <x-select
                label="Reason"
                wire:model="unavailableReason"
                :options="$this->unavailableReasonOptions"
                option-value="value"
                option-label="label"
                placeholder="Select a reason..."
            />

            <x-textarea
                label="Note (optional)"
                wire:model="unavailableNote"
                placeholder="e.g., Finals blown on the amp, swapping radios"
                rows="3"
                maxlength="500"
            />
        </div>

        <x-slot:actions class="flex-wrap max-sm:w-full">
            <x-button
                label="Cancel"
                wire:click="cancelUnavailable"
                class="w-full sm:w-auto"
            />
            <x-button
                label="Mark Unavailable"
                wire:click="markUnavailable"
                class="btn-error w-full sm:w-auto"
                icon="phosphor-prohibit"
                spinner="markUnavailable"
            />
        </x-slot:actions>
    </x-modal>
    @endcan
</div>
