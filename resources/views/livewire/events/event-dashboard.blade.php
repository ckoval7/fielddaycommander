<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
                <h1 class="text-3xl font-bold">{{ $event->name }}</h1>
                <x-badge value="{{ $event->eventType->name ?? 'N/A' }}" class="badge-outline" />
                @if($event->status === 'active')
                    <x-badge value="Active" class="badge-success" />
                @elseif($event->status === 'upcoming')
                    <x-badge value="Upcoming" class="badge-info" />
                @else
                    <x-badge value="Completed" class="badge-neutral" />
                @endif
            </div>
            <p class="text-base-content/60">
                {{ $event->start_time ? toLocalTime($event->start_time)->format('F j, Y g:i A T') : 'Start not set' }}
                -
                {{ $event->end_time ? toLocalTime($event->end_time)->format('F j, Y g:i A T') : 'End not set' }}
            </p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-2">
            @can('edit-events')
                <x-button
                    label="Edit"
                    icon="o-pencil"
                    class="btn-outline"
                    link="{{ route('events.edit', ['eventId' => $event->id]) }}"
                    wire:navigate
                />
            @endcan

            @can('create-events')
                <x-button
                    label="Clone"
                    icon="o-document-duplicate"
                    class="btn-outline"
                    link="{{ route('events.clone', ['eventId' => $event->id]) }}"
                    wire:navigate
                />
            @endcan

            @can('delete-events')
                <x-button
                    label="Delete"
                    icon="o-trash"
                    class="btn-outline btn-error"
                    link="{{ route('events.index') }}"
                    wire:navigate
                />
            @endcan
        </div>
    </div>

    <!-- Tabs -->
    <x-tabs wire:model="activeTab">
        <!-- Tab 1 - Overview -->
        <x-tab name="overview" label="Overview" icon="o-home">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <!-- Configuration Card -->
                <x-card title="Configuration" shadow class="col-span-1 md:col-span-2">
                    @if($event->eventConfiguration)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <!-- Callsign -->
                            <div>
                                <div class="text-sm text-base-content/60">Callsign</div>
                                <div class="font-mono text-lg font-semibold">{{ $event->eventConfiguration->callsign }}</div>
                            </div>

                            <!-- Club Name -->
                            <div>
                                <div class="text-sm text-base-content/60">Club Name</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->club_name ?? 'N/A' }}</div>
                            </div>

                            <!-- Section -->
                            <div>
                                <div class="text-sm text-base-content/60">Section</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->section->code ?? 'N/A' }}</div>
                            </div>

                            <!-- Operating Class -->
                            <div>
                                <div class="text-sm text-base-content/60">Operating Class</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->operatingClass->code ?? 'N/A' }}</div>
                            </div>

                            <!-- Transmitters -->
                            <div>
                                <div class="text-sm text-base-content/60">Transmitters</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->transmitter_count }}</div>
                            </div>

                            <!-- Power -->
                            <div>
                                <div class="text-sm text-base-content/60">Max Power</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->max_power_watts }}W</div>
                            </div>

                            <!-- Power Multiplier -->
                            <div>
                                <div class="text-sm text-base-content/60">Power Multiplier</div>
                                <div class="font-semibold">{{ $event->eventConfiguration->calculatePowerMultiplier() }}x</div>
                            </div>

                            <!-- Power Sources -->
                            <div class="col-span-1 md:col-span-2 lg:col-span-3">
                                <div class="text-sm text-base-content/60 mb-2">Power Sources</div>
                                <div class="flex flex-wrap gap-2">
                                    @if($event->eventConfiguration->uses_commercial_power)
                                        <x-badge value="Commercial" class="badge-neutral" />
                                    @endif
                                    @if($event->eventConfiguration->uses_generator)
                                        <x-badge value="Generator" class="badge-neutral" />
                                    @endif
                                    @if($event->eventConfiguration->uses_battery)
                                        <x-badge value="Battery" class="badge-success" />
                                    @endif
                                    @php
                                        $alternatePowerSources = [];
                                        if($event->eventConfiguration->uses_solar) $alternatePowerSources[] = 'Solar';
                                        if($event->eventConfiguration->uses_wind) $alternatePowerSources[] = 'Wind';
                                        if($event->eventConfiguration->uses_water) $alternatePowerSources[] = 'Water';
                                        if($event->eventConfiguration->uses_methane) $alternatePowerSources[] = 'Methane';
                                    @endphp
                                    @if(count($alternatePowerSources) > 0)
                                        <x-badge value="Alternate Power ({{ implode(', ', $alternatePowerSources) }})" class="badge-success" />
                                    @endif
                                    @if($event->eventConfiguration->uses_other_power)
                                        <x-badge value="Other" class="badge-neutral" />
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-center py-8 text-base-content/60">
                            <x-icon name="o-exclamation-triangle" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                            <p>No configuration found for this event.</p>
                        </div>
                    @endif
                </x-card>

                <!-- GOTA Station Card (if enabled) -->
                @if($event->eventConfiguration && $event->eventConfiguration->has_gota_station)
                    <x-card title="GOTA Station" shadow>
                        <div>
                            <div class="text-sm text-base-content/60">GOTA Callsign</div>
                            <div class="font-mono text-lg font-semibold">{{ $event->eventConfiguration->gota_callsign ?? 'Not set' }}</div>
                        </div>
                    </x-card>
                @endif

                <!-- Scoring Summary Card -->
                <x-card title="Scoring Summary" shadow>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-base-content/60">Contacts</div>
                            <div class="text-2xl font-bold">{{ number_format($this->qsoBreakdown['total_contacts']) }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">QSO Points</div>
                            <div class="text-2xl font-bold">{{ number_format($event->eventConfiguration?->calculateQsoScore() ?? 0) }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">Bonus Points</div>
                            <div class="text-2xl font-bold">{{ number_format($event->eventConfiguration?->calculateBonusScore() ?? 0) }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">Final Score</div>
                            <div class="text-2xl font-bold text-primary">{{ number_format($event->final_score) }}</div>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-base-content/60">
                        {{-- Placeholder note: Will be populated when contacts are logged --}}
                        Note: Scoring will update in real-time as contacts are logged.
                    </div>
                </x-card>

                <!-- Participants Card -->
                <x-card title="Participants" shadow>
                    @if(count($this->participants) > 0)
                        <div class="space-y-2">
                            @foreach($this->participants as $participant)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-user" class="w-4 h-4" />
                                    <span>{{ $participant['name'] }}</span>
                                    <span class="text-xs text-base-content/60">({{ $participant['contact_count'] }} contacts)</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-4 text-base-content/60">
                            <x-icon name="o-users" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                            <p>No participants yet</p>
                        </div>
                    @endif
                </x-card>

                @if($event->eventConfiguration && $event->eventConfiguration->guestbook_enabled)
                    <!-- Guestbook Visitors Card -->
                    <x-card title="Guestbook Visitors" shadow>
                        <div class="space-y-4">
                            <!-- Total Visitors -->
                            <div>
                                <div class="text-sm text-base-content/60">Total Visitors</div>
                                <div class="text-2xl font-bold">{{ number_format($this->guestbookStats['total']) }}</div>
                            </div>

                            <!-- Bonus Eligible -->
                            <div>
                                <div class="text-sm text-base-content/60">Verified Bonus Eligible</div>
                                <div class="text-2xl font-bold">{{ $this->guestbookStats['verified_bonus_eligible'] }} / 10</div>
                            </div>

                            <!-- Bonus Points -->
                            <div>
                                <div class="text-sm text-base-content/60">PR Bonus</div>
                                <div class="text-2xl font-bold text-success">{{ number_format($this->guestbookStats['bonus_points']) }} pts</div>
                            </div>

                            <!-- Link to Guestbook Manager -->
                            @can('manage-guestbook')
                                <div class="mt-4 pt-4 border-t border-base-300">
                                    <x-button
                                        label="Manage Guestbook"
                                        icon="o-book-open"
                                        class="btn-outline btn-sm w-full"
                                        link="{{ route('events.guestbook', ['event' => $event->id]) }}"
                                        wire:navigate
                                    />
                                </div>
                            @endcan
                        </div>
                    </x-card>
                @endif
            </div>
        </x-tab>

        <!-- Tab 2 - Recent Contacts -->
        <x-tab name="contacts" label="Recent Contacts" icon="o-radio">
            <div class="mt-6">
                <x-card shadow>
                    <div class="text-center py-12 text-base-content/60">
                        <x-icon name="o-radio" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                        <p class="text-lg font-semibold mb-2">Recent Contacts</p>
                        <p>This section will display recent QSOs once the contact logging is implemented.</p>
                    </div>
                </x-card>
            </div>
        </x-tab>

        <!-- Tab 3 - Band/Mode Grid -->
        <x-tab name="bandmode" label="Band/Mode Grid" icon="o-table-cells">
            <div class="mt-6">
                <x-card shadow>
                    <div class="text-center py-12 text-base-content/60">
                        <x-icon name="o-table-cells" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                        <p class="text-lg font-semibold mb-2">Band/Mode Grid</p>
                        <p>This section will display a grid of contacts by band and mode.</p>
                    </div>
                </x-card>
            </div>
        </x-tab>

        <!-- Tab 4 - Bonuses -->
        <x-tab name="bonuses" label="Bonuses" icon="o-trophy">
            <div class="mt-6">
                <x-card shadow>
                    <div class="text-center py-12 text-base-content/60">
                        <x-icon name="o-trophy" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                        <p class="text-lg font-semibold mb-2">Bonus Points</p>
                        <p>This section will display claimed and verified bonus points.</p>
                    </div>
                </x-card>
            </div>
        </x-tab>

        <!-- Tab 5 - Equipment -->
        @can('manage-event-equipment')
            <x-tab name="equipment" label="Equipment" icon="o-wrench-screwdriver">
                <div class="mt-6">
                    <x-card shadow>
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            <x-icon name="o-wrench-screwdriver" class="w-16 h-16 mx-auto opacity-50 mb-4 text-base-content/60" />
                            <p class="text-lg font-semibold mb-2">Equipment Management</p>
                            <p class="text-base-content/60 mb-6">Manage all equipment commitments and assignments for this event.</p>
                            <x-button
                                label="Go to Equipment Dashboard"
                                icon="o-arrow-right"
                                class="btn-primary"
                                link="{{ route('events.equipment.dashboard', ['event' => $event->id]) }}"
                                wire:navigate
                            />
                        </div>
                    </x-card>
                </div>
            </x-tab>
        @elsecan('view-all-equipment')
            <x-tab name="equipment" label="Equipment" icon="o-wrench-screwdriver">
                <div class="mt-6">
                    <x-card shadow>
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            <x-icon name="o-wrench-screwdriver" class="w-16 h-16 mx-auto opacity-50 mb-4 text-base-content/60" />
                            <p class="text-lg font-semibold mb-2">Equipment Management</p>
                            <p class="text-base-content/60 mb-6">View all equipment commitments and assignments for this event.</p>
                            <x-button
                                label="View Equipment Dashboard"
                                icon="o-arrow-right"
                                class="btn-primary"
                                link="{{ route('events.equipment.dashboard', ['event' => $event->id]) }}"
                                wire:navigate
                            />
                        </div>
                    </x-card>
                </div>
            </x-tab>
        @endcan
    </x-tabs>
</div>
