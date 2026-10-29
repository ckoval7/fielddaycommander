{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-header use-h1 title="Select Station" subtitle="Choose a station to begin logging" separator />

    @if($this->operatingPeriodEnded)
        <x-card>
            <div class="text-center py-8">
                <x-icon name="phosphor-clock-countdown" class="w-12 h-12 mx-auto text-base-content/30" />
                <h3 class="mt-4 text-lg font-semibold">Operating Period Over</h3>
                <p class="mt-2 text-base-content/70">This event's 24-hour operating period has ended. Use Transcribe Paper Log to enter contacts from paper logs.</p>
            </div>
        </x-card>
    @elseif(! $this->activeEvent)
        <x-card>
            <div class="text-center py-8">
                <x-icon name="phosphor-cell-signal-slash" class="w-12 h-12 mx-auto text-base-content/30" />
                <h2 class="mt-4 text-lg font-semibold">No Active Event</h2>
                <p class="mt-2 text-base-content/70">No event is running. Station setup opens 15 minutes before the next event starts.</p>
            </div>
        </x-card>
    @else
        {{-- Columns follow the space beside the sidebar, not the screen width. --}}
        <div class="@container">
        <div class="grid grid-cols-1 sm:grid-cols-2 @3xl:grid-cols-3 gap-4 sm:gap-6">
            @forelse($this->stations as $station)
                @php
                    $status = $station->computed_status;
                    $session = $station->active_session;
                    $borderClass = match($status) {
                        'available' => 'border-success/50',
                        'idle' => 'border-warning/50',
                        'occupied' => 'border-base-300',
                        'unavailable' => 'border-base-300',
                        default => '',
                    };
                @endphp

                <x-card class="border {{ $borderClass }}">
                    <div class="flex items-start justify-between">
                        <div class="min-w-0">
                            <h2 class="font-bold text-base sm:text-lg truncate">{{ $station->name }}</h2>
                            @if($station->is_gota)
                                <x-badge value="GOTA" class="badge-xs badge-info mt-1" />
                            @endif
                        </div>
                        @unless($status === 'unavailable')
                            <x-badge
                                :value="ucfirst($status)"
                                @class([
                                    'badge-sm',
                                    'badge-success' => $status === 'available',
                                    'badge-warning' => $status === 'idle',
                                    'badge-ghost' => $status === 'occupied',
                                ])
                            />
                        @endunless
                    </div>

                    @if($session)
                        <div class="mt-3 space-y-1 text-xs sm:text-sm text-base-content/70">
                            <div class="flex justify-between">
                                <span>Operator:</span>
                                <span class="font-medium text-base-content">
                                    {{ $session->operator?->call_sign ?? 'Unknown' }}
                                    @if($station->is_external_session)
                                        <span class="text-base-content/60">({{ strtoupper($session->external_source) }})</span>
                                    @endif
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span>Band/Mode:</span>
                                <span class="font-medium text-base-content">{{ $session->band?->name }} {{ $session->mode?->name }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>QSOs:</span>
                                <span class="font-medium text-base-content">{{ $session->qso_count ?? 0 }}</span>
                            </div>
                        </div>
                    @endif

                    <div class="mt-4">
                        @if($status === 'available')
                            <x-button
                                label="Select Station"
                                wire:click="selectStation({{ $station->id }})"
                                class="btn-success btn-sm w-full"
                                icon="phosphor-play"
                                spinner="selectStation({{ $station->id }})"
                            />
                        @elseif($status === 'idle')
                            <x-button
                                label="Take Over"
                                wire:click="selectStation({{ $station->id }})"
                                class="btn-warning btn-sm w-full"
                                icon="phosphor-arrow-clockwise"
                                spinner="selectStation({{ $station->id }})"
                            />
                        @elseif($status === 'unavailable')
                            <x-button
                                label="Select Station"
                                class="btn-sm w-full"
                                icon="phosphor-play"
                                disabled
                            />
                            <div class="mt-2 text-xs sm:text-sm text-base-content/70" data-station-unavailable>
                                <div class="flex items-center gap-2 font-medium">
                                    <x-icon :name="$station->unavailable_reason->icon()" class="w-4 h-4 flex-shrink-0" />
                                    Down: {{ $station->unavailable_reason->label() }}
                                </div>
                                @if($station->unavailable_note)
                                    <p class="mt-1 break-words">{{ $station->unavailable_note }}</p>
                                @endif
                            </div>
                        @else
                            <div class="flex items-center justify-center gap-2 h-8 text-sm font-medium text-base-content/70" data-station-in-use>
                                <x-icon name="phosphor-lock" class="w-4 h-4" />
                                In Use
                            </div>
                        @endif
                    </div>
                </x-card>
            @empty
                <div class="col-span-full">
                    <x-card>
                        <div class="text-center py-8">
                            <x-icon name="phosphor-hard-drives" class="w-12 h-12 mx-auto text-base-content/30" />
                            <h2 class="mt-4 text-lg font-semibold">No Stations Configured</h2>
                            <p class="mt-2 text-base-content/70">No stations are set up for this event. Contact your event administrator.</p>
                        </div>
                    </x-card>
                </div>
            @endforelse
        </div>
        </div>
    @endif

    {{-- Session Setup Modal --}}
    <x-modal wire:model="showSetupModal" title="Start Logging Session" persistent>
        <div class="space-y-4">
            @error('selectedStationId')
                <x-alert icon="phosphor-prohibit" class="alert-error text-sm">{{ $message }}</x-alert>
            @enderror

            @php $supportedBands = $this->stationSupportedBands; @endphp
            @if($supportedBands === null)
                @php $stationForBands = $this->stations?->firstWhere('id', $selectedStationId); @endphp
                @if($stationForBands && ! $stationForBands->primaryRadio)
                    <x-alert icon="phosphor-info" class="alert-info text-sm">
                        No radio assigned. Band compatibility is unknown.
                    </x-alert>
                @elseif($stationForBands)
                    <x-alert icon="phosphor-info" class="alert-info text-sm">
                        No antennas assigned. Band compatibility is unknown.
                    </x-alert>
                @endif
            @elseif($supportedBands->isEmpty())
                <x-alert icon="phosphor-warning" class="alert-warning text-sm">
                    No bands are supported by both the radio and antennas at this station.
                </x-alert>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-base-content/70">Station supports:</span>
                    @foreach($supportedBands as $supportedBand)
                        <x-badge :value="$supportedBand->name" class="badge-outline badge-sm" />
                    @endforeach
                </div>
            @endif

            <x-select
                label="Band"
                wire:model.live="selectedBandId"
                :options="$this->bands->map(fn($b) => ['value' => $b->id, 'label' => $b->name])"
                option-value="value"
                option-label="label"
                placeholder="Select a band"
            />

            @if($this->bandWarning)
                <x-alert
                    :icon="$this->bandWarning['type'] === 'warning' ? 'phosphor-warning' : 'phosphor-info'"
                    @class([
                        'alert-warning' => $this->bandWarning['type'] === 'warning',
                        'alert-info' => $this->bandWarning['type'] === 'info',
                    ])
                >
                    {{ $this->bandWarning['message'] }}
                </x-alert>
            @endif

            <x-select
                label="Mode"
                wire:model.live="selectedModeId"
                :options="$this->modes->map(fn($m) => ['value' => $m->id, 'label' => $m->name])"
                option-value="value"
                option-label="label"
                placeholder="Select a mode"
            />

            @if($this->bandModeConflictWarning)
                <x-alert icon="phosphor-warning" class="alert-warning" data-band-mode-warning>
                    {{ $this->bandModeConflictWarning }}
                </x-alert>
            @endif

            <x-input
                label="Power (watts)"
                wire:model="powerWatts"
                type="number"
                min="1"
                :max="$this->maxPowerWatts"
                suffix="W"
            />

            @php $selectedStation = $this->stations?->firstWhere('id', $selectedStationId); @endphp
            @if($selectedStation?->is_gota)
                <div class="divider text-xs text-base-content/70">GOTA Station Options</div>
                <label class="flex items-center gap-3 cursor-pointer py-2">
                    <input type="checkbox" wire:model="isSupervisedSession" class="toggle toggle-sm toggle-primary" />
                    <div>
                        <span class="text-sm font-medium">Supervised Session (GOTA Coach present)</span>
                        <span class="block text-xs text-base-content/60">A designated GOTA coach must supervise at least 10 contacts for the 100-point bonus.</span>
                    </div>
                </label>
            @endif
        </div>

        <x-slot:actions>
            {{-- Stacked on phones: two full-width buttons side by side push Cancel off the left edge --}}
            <div class="flex flex-col sm:flex-row sm:justify-end gap-2 w-full">
                <x-button
                    label="Cancel"
                    wire:click="cancelSetup"
                    class="w-full sm:w-auto"
                />
                <x-button
                    label="Start Session"
                    wire:click="startSession"
                    class="btn-primary w-full sm:w-auto"
                    icon="phosphor-play"
                    spinner="startSession"
                />
            </div>
        </x-slot:actions>
    </x-modal>

    {{-- Takeover Confirmation Modal --}}
    <x-modal wire:model="showTakeoverModal" title="Take Over Station" persistent>
        @php $takeoverStation = $this->stations?->firstWhere('id', $takeoverStationId); @endphp
        @if($takeoverStation)
            <x-alert icon="phosphor-warning" class="alert-warning">
                This station has an idle session. Taking over will end the current operator's session.
            </x-alert>

            <div class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-base-content/70">Station:</span>
                    <span class="font-medium">{{ $takeoverStation->name }}</span>
                </div>
                @if($takeoverStation->active_session)
                    <div class="flex justify-between">
                        <span class="text-base-content/70">Current Operator:</span>
                        <span class="font-medium">{{ $takeoverStation->active_session->operator?->call_sign ?? 'Unknown' }}</span>
                    </div>
                @endif
            </div>
        @endif

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="cancelTakeover"
                class="w-full sm:w-auto"
            />
            <x-button
                label="Take Over Station"
                wire:click="confirmTakeover"
                class="btn-warning w-full sm:w-auto"
                icon="phosphor-arrow-clockwise"
                spinner="confirmTakeover"
            />
        </x-slot:actions>
    </x-modal>
</div>
