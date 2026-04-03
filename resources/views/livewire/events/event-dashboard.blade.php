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

                <!-- Manual Bonus Claims -->
                <livewire:events.manual-bonus-claims :event="$event" />

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

                            <!-- Guestbook Bonuses -->
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-base-content/60">Elected Official</span>
                                    @if($this->guestbookStats['elected_official'])
                                        <span class="badge badge-success badge-sm">+100</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">--</span>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-base-content/60">Served Agency</span>
                                    @if($this->guestbookStats['agency'])
                                        <span class="badge badge-success badge-sm">+100</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">--</span>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-base-content/60">Media Publicity</span>
                                    @if($this->guestbookStats['media'])
                                        <span class="badge badge-success badge-sm">+100</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">--</span>
                                    @endif
                                </div>
                            </div>

                            <!-- Bonus Points Total -->
                            <div>
                                <div class="text-sm text-base-content/60">Guestbook Bonus</div>
                                <div class="text-2xl font-bold {{ $this->guestbookStats['bonus_points'] > 0 ? 'text-success' : '' }}">{{ number_format($this->guestbookStats['bonus_points']) }} pts</div>
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
                    @if($this->recentContacts->isEmpty())
                        <div class="text-center py-8 text-base-content/60">
                            <x-icon name="o-radio" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                            <p>No contacts logged yet.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Callsign</th>
                                        <th>Band</th>
                                        <th>Mode</th>
                                        <th>Operator</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->recentContacts as $contact)
                                        <tr>
                                            <td class="tabular-nums text-base-content/60">{{ toLocalTime($contact->qso_time)->format('H:i') }}</td>
                                            <td class="font-mono font-semibold">{{ $contact->callsign }}</td>
                                            <td>{{ $contact->band?->name }}</td>
                                            <td>{{ $contact->mode?->name }}</td>
                                            <td class="text-base-content/60">
                                                @if($contact->is_gota_contact)
                                                    {{ $contact->gotaOperator?->call_sign ?? $contact->gota_operator_callsign ?? $contact->gota_operator_first_name . ' ' . $contact->gota_operator_last_name }}
                                                @else
                                                    {{ $contact->logger?->call_sign }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="text-xs text-base-content/40 mt-3">
                            Showing last {{ $this->recentContacts->count() }} contacts
                        </div>
                    @endif
                </x-card>
            </div>
        </x-tab>

        <!-- Tab 3 - Scoring (combines Band/Mode Grid + Bonuses) -->
        <x-tab name="scoring" label="Scoring" icon="o-trophy">
            <div class="mt-6 space-y-6">

                {{-- Score Headline --}}
                <x-card shadow>
                    <div class="flex flex-wrap items-center justify-center gap-4 md:gap-8 py-4">
                        <div class="text-center">
                            <div class="text-3xl font-black tabular-nums">{{ number_format($this->scoringTotals['qso_base_points']) }}</div>
                            <div class="text-xs uppercase tracking-widest text-base-content/60 mt-1">QSO Base Pts</div>
                        </div>
                        <span class="text-2xl font-light text-base-content/30">&times;</span>
                        <div class="text-center">
                            <div class="text-3xl font-black tabular-nums">{{ $this->scoringTotals['power_multiplier'] }}&times;</div>
                            <div class="text-xs uppercase tracking-widest text-base-content/60 mt-1">Power Multi.</div>
                        </div>
                        <span class="text-2xl font-light text-base-content/30">+</span>
                        <div class="text-center">
                            <div class="text-3xl font-black tabular-nums">{{ number_format($this->scoringTotals['bonus_score']) }}</div>
                            <div class="text-xs uppercase tracking-widest text-base-content/60 mt-1">Bonus Pts</div>
                        </div>
                        @if($this->scoringTotals['has_gota'])
                            <span class="text-2xl font-light text-base-content/30">+</span>
                            <div class="text-center">
                                <div class="text-3xl font-black tabular-nums">{{ number_format($this->scoringTotals['gota_bonus']) }}</div>
                                <div class="text-xs uppercase tracking-widest text-base-content/60 mt-1">GOTA Bonus</div>
                            </div>
                        @endif
                        <span class="text-2xl font-light text-base-content/30">=</span>
                        <div class="text-center">
                            <div class="text-4xl font-black tabular-nums text-primary">{{ number_format($this->scoringTotals['final_score']) }}</div>
                            <div class="text-xs uppercase tracking-widest text-base-content/60 mt-1 font-bold">Final Score</div>
                        </div>
                    </div>
                </x-card>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    {{-- Column 1: QSO Points + Band/Mode Grid --}}
                    <x-card title="QSO Points" shadow class="lg:col-span-2">
                        {{-- Mode scoring key --}}
                        <div class="flex flex-wrap gap-2 mb-4">
                            @foreach($this->modes as $mode)
                                <span class="badge badge-outline badge-sm">
                                    {{ $mode->name }} = {{ $mode->points_fd }} {{ $mode->points_fd === 1 ? 'pt' : 'pts' }}
                                </span>
                            @endforeach
                        </div>

                        @if(count($this->bandModeGrid) > 0 && collect($this->bandModeGrid)->sum('total_count') > 0)
                            <div class="overflow-x-auto">
                                <table class="table table-xs">
                                    <thead>
                                        <tr>
                                            <th>Mode</th>
                                            @foreach($this->bands as $band)
                                                <th class="text-center">{{ $band->name }}</th>
                                            @endforeach
                                            <th class="text-right">QSOs</th>
                                            <th class="text-right">Pts</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->bandModeGrid as $row)
                                            <tr>
                                                <td class="font-semibold">{{ $row['mode']->name }}</td>
                                                @foreach($this->bands as $band)
                                                    <td class="text-center tabular-nums">
                                                        @if($row['cells'][$band->id] > 0)
                                                            <span class="font-bold">{{ $row['cells'][$band->id] }}</span>
                                                        @else
                                                            <span class="text-base-content/20">&mdash;</span>
                                                        @endif
                                                    </td>
                                                @endforeach
                                                <td class="text-right font-bold tabular-nums">{{ $row['total_count'] ?: '—' }}</td>
                                                <td class="text-right tabular-nums text-base-content/60">{{ $row['total_points'] ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="font-bold">
                                            <td>Total</td>
                                            @foreach($this->bands as $band)
                                                <td class="text-center tabular-nums">{{ ($this->bandColumnTotals[$band->id] ?? 0) ?: '—' }}</td>
                                            @endforeach
                                            <td class="text-right tabular-nums">{{ collect($this->bandModeGrid)->sum('total_count') }}</td>
                                            <td class="text-right tabular-nums">{{ number_format($this->scoringTotals['qso_base_points']) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-6 text-base-content/40">
                                No contacts logged yet.
                            </div>
                        @endif
                    </x-card>

                    {{-- Column 2: Bonus Points --}}
                    <x-card title="Bonus Points" shadow>
                        {{-- Summary row --}}
                        <div class="grid grid-cols-3 gap-2 mb-4 text-center">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-base-content/60">Verified</div>
                                <div class="text-lg font-bold tabular-nums text-success">{{ number_format($this->bonusSummary['verified_pts']) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-base-content/60">Claimed</div>
                                <div class="text-lg font-bold tabular-nums text-warning">{{ number_format($this->bonusSummary['claimed_pts']) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-base-content/60">Unclaimed</div>
                                <div class="text-lg font-bold tabular-nums text-base-content/40">{{ $this->bonusSummary['unclaimed_count'] }}</div>
                            </div>
                        </div>

                        {{-- Bonus checklist --}}
                        <div class="divide-y divide-base-200">
                            @foreach($this->bonusList as $item)
                                <div class="flex items-center justify-between gap-2 py-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium truncate">{{ $item['type']->name }}</div>
                                        @if($item['type']->is_per_occurrence && $item['bonus'])
                                            <div class="text-xs text-base-content/60">
                                                {{ $item['bonus']->quantity }} &times; {{ $item['type']->base_points }} pts
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="text-xs tabular-nums font-semibold {{ $item['status'] !== 'unclaimed' ? 'text-base-content' : 'text-base-content/40' }}">
                                            @if($item['points'] > 0) +{{ $item['points'] }} @else {{ $item['type']->base_points }} pts @endif
                                        </span>
                                        @if($item['status'] === 'verified')
                                            <x-badge value="Verified" class="badge-success badge-xs" />
                                        @elseif($item['status'] === 'claimed')
                                            <x-badge value="Claimed" class="badge-warning badge-xs" />
                                        @else
                                            <x-badge value="Unclaimed" class="badge-ghost badge-xs" />
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if(empty($this->bonusList))
                                <div class="text-center py-4 text-base-content/40">
                                    No bonus types configured.
                                </div>
                            @endif
                        </div>
                    </x-card>
                </div>
            </div>
        </x-tab>

        <!-- Tab 4 - Equipment -->
        @canany(['manage-event-equipment', 'view-all-equipment'])
            <x-tab name="equipment" label="Equipment" icon="o-wrench-screwdriver">
                <div class="mt-6">
                    @if($event->status !== 'completed')
                        {{-- Active/Upcoming: summary + link to dashboard --}}
                        <x-card shadow>
                            @if($this->equipmentCommitments->isNotEmpty())
                                @php
                                    $statuses = $this->equipmentCommitments->groupBy('status');
                                @endphp
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                    <div>
                                        <div class="text-sm text-base-content/60">Total</div>
                                        <div class="text-2xl font-bold">{{ $this->equipmentCommitments->count() }}</div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-base-content/60">Committed</div>
                                        <div class="text-2xl font-bold text-info">{{ $statuses->get('committed', collect())->count() }}</div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-base-content/60">Delivered</div>
                                        <div class="text-2xl font-bold text-success">{{ $statuses->get('delivered', collect())->count() }}</div>
                                    </div>
                                </div>
                            @endif
                            <div class="flex justify-center">
                                <x-button
                                    label="{{ auth()->user()->can('manage-event-equipment') ? 'Go to Equipment Dashboard' : 'View Equipment Dashboard' }}"
                                    icon="o-arrow-right"
                                    class="btn-primary"
                                    link="{{ route('events.equipment.dashboard', ['event' => $event->id]) }}"
                                    wire:navigate
                                />
                            </div>
                        </x-card>
                    @else
                        {{-- Completed: historical read-only list --}}
                        <x-card title="Equipment Used" shadow>
                            @if($this->equipmentCommitments->isEmpty())
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-wrench-screwdriver" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                                    <p>No equipment was committed to this event.</p>
                                </div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Equipment</th>
                                                <th>Type</th>
                                                <th>Owner</th>
                                                <th>Station</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($this->equipmentCommitments as $commitment)
                                                <tr>
                                                    <td>
                                                        <div class="font-semibold">
                                                            @if($commitment->equipment->make || $commitment->equipment->model)
                                                                {{ $commitment->equipment->make }} {{ $commitment->equipment->model }}
                                                            @else
                                                                {{ $commitment->equipment->name ?? 'Unknown' }}
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <x-badge value="{{ ucfirst($commitment->equipment->type) }}" class="badge-outline badge-sm" />
                                                    </td>
                                                    <td class="text-base-content/60">
                                                        {{ $commitment->equipment->owner?->name ?? $commitment->equipment->owningOrganization?->name ?? '—' }}
                                                    </td>
                                                    <td class="text-base-content/60">
                                                        {{ $commitment->station?->name ?? 'Unassigned' }}
                                                    </td>
                                                    <td>
                                                        @php
                                                            $statusColor = match($commitment->status) {
                                                                'returned' => 'badge-success',
                                                                'delivered' => 'badge-info',
                                                                'committed' => 'badge-neutral',
                                                                'cancelled' => 'badge-ghost',
                                                                'lost' => 'badge-error',
                                                                'damaged' => 'badge-warning',
                                                                default => 'badge-ghost',
                                                            };
                                                        @endphp
                                                        <x-badge value="{{ ucfirst($commitment->status) }}" class="{{ $statusColor }} badge-sm" />
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </x-card>
                    @endif
                </div>
            </x-tab>
        @endcanany
    </x-tabs>
</div>
