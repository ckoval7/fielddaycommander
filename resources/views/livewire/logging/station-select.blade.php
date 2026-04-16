<div>
    <x-header title="Station Select" subtitle="Choose a station to begin logging" separator />

    @if(! $this->activeEvent)
        <x-card>
            <div class="text-center py-8">
                <x-icon name="phosphor-cell-signal-slash" class="w-12 h-12 mx-auto text-base-content/30" />
                <h3 class="mt-4 text-lg font-semibold">No Active Event</h3>
                <p class="mt-2 text-base-content/70">There is no event currently in progress. Logging is only available during an active event.</p>
            </div>
        </x-card>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            @forelse($this->stations as $station)
                @php
                    $status = $station->computed_status;
                    $session = $station->active_session;
                    $borderClass = match($status) {
                        'available' => 'border-success/50',
                        'idle' => 'border-warning/50',
                        'occupied' => 'border-base-300',
                        default => '',
                    };
                @endphp

                <x-card class="border {{ $borderClass }}">
                    <div class="flex items-start justify-between">
                        <div class="min-w-0">
                            <h3 class="font-bold text-base sm:text-lg truncate">{{ $station->name }}</h3>
                            @if($station->is_gota)
                                <x-badge value="GOTA" class="badge-xs badge-info mt-1" />
                            @endif
                        </div>
                        <x-badge
                            :value="ucfirst($status)"
                            @class([
                                'badge-sm',
                                'badge-success' => $status === 'available',
                                'badge-warning' => $status === 'idle',
                                'badge-ghost' => $status === 'occupied',
                            ])
                        />
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
                        @else
                            <x-button
                                label="In Use"
                                class="btn-ghost btn-sm w-full"
                                icon="phosphor-lock"
                                disabled
                            />
                        @endif
                    </div>
                </x-card>
            @empty
                <div class="col-span-full">
                    <x-card>
                        <div class="text-center py-8">
                            <x-icon name="phosphor-hard-drives" class="w-12 h-12 mx-auto text-base-content/30" />
                            <h3 class="mt-4 text-lg font-semibold">No Stations Configured</h3>
                            <p class="mt-2 text-base-content/70">No stations have been set up for this event yet.</p>
                        </div>
                    </x-card>
                </div>
            @endforelse
        </div>
    @endif

    {{-- Session Setup Modal --}}
    <x-modal wire:model="showSetupModal" title="Start Logging Session" persistent>
        <div class="space-y-4">
            @php $supportedBands = $this->stationSupportedBands; @endphp
            @if($supportedBands === null)
                @php $stationForBands = $this->stations?->firstWhere('id', $selectedStationId); @endphp
                @if($stationForBands && ! $stationForBands->primaryRadio)
                    <x-alert icon="phosphor-info" class="alert-info text-sm">
                        No radio assigned — band compatibility unknown.
                    </x-alert>
                @elseif($stationForBands)
                    <x-alert icon="phosphor-info" class="alert-info text-sm">
                        No antennas assigned — band compatibility unknown.
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
                placeholder="Select a band..."
            />

            @if($this->bandWarning)
                <x-alert
                    :icon="$this->bandWarning['type'] === 'warning' ? 'o-exclamation-triangle' : 'o-information-circle'"
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
                wire:model="selectedModeId"
                :options="$this->modes->map(fn($m) => ['value' => $m->id, 'label' => $m->name])"
                option-value="value"
                option-label="label"
                placeholder="Select a mode..."
            />

            <x-input
                label="Power (watts)"
                wire:model="powerWatts"
                type="number"
                min="1"
                max="1500"
                suffix="W"
            />

            @php $selectedStation = $this->stations?->firstWhere('id', $selectedStationId); @endphp
            @if($selectedStation?->is_gota)
                <div class="divider text-xs text-base-content/50">GOTA Station Options</div>
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" wire:model="isSupervisedSession" class="toggle toggle-sm toggle-primary" />
                    <div>
                        <span class="label-text font-medium">Supervised Session (GOTA Coach present)</span>
                        <span class="label-text-alt block text-xs text-base-content/60">Earn 100-point coach bonus if 10+ contacts are supervised</span>
                    </div>
                </label>
            @endif
        </div>

        <x-slot:actions>
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
