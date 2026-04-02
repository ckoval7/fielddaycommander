<div class="space-y-6">
    {{-- Page Header --}}
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
                <h1 class="text-3xl font-bold">Equipment Dashboard</h1>
                <x-badge value="{{ $event->name }}" class="badge-outline" />
            </div>
            <p class="text-base-content/60">
                Manage all equipment commitments for {{ $event->name }}
            </p>
        </div>

        {{-- Quick Actions --}}
        <div class="flex flex-wrap gap-2">
            @if($this->canManage)
                <x-button
                    label="Commit Club Equipment"
                    icon="o-plus"
                    class="btn-primary"
                    wire:click="openCommitModal"
                />

                <x-dropdown>
                    <x-slot:trigger>
                        <x-button label="Export" icon="o-document-arrow-down" class="btn-outline" />
                    </x-slot:trigger>

                    {{-- Pre-Event Reports --}}
                    <x-menu-separator title="Pre-Event" />
                    <x-menu-item
                        title="Commitment Summary (CSV)"
                        icon="o-document-text"
                        link="{{ route('events.equipment.reports.commitment-summary', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Delivery Checklist (PDF)"
                        icon="o-clipboard-document-check"
                        link="{{ route('events.equipment.reports.delivery-checklist', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Station Inventory (PDF)"
                        icon="o-building-office"
                        link="{{ route('events.equipment.reports.station-inventory-pdf', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Station Inventory (CSV)"
                        icon="o-table-cells"
                        link="{{ route('events.equipment.reports.station-inventory-csv', ['event' => $event->id]) }}"
                    />

                    {{-- Post-Event Reports --}}
                    <x-menu-separator title="Post-Event" />
                    <x-menu-item
                        title="Return Checklist (PDF)"
                        icon="o-arrow-uturn-left"
                        link="{{ route('events.equipment.reports.return-checklist', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Historical Record (CSV)"
                        icon="o-archive-box"
                        link="{{ route('events.equipment.reports.historical-record', ['event' => $event->id]) }}"
                    />

                    {{-- Other Reports --}}
                    <x-menu-separator title="Reference & Incidents" />
                    <x-menu-item
                        title="Owner Contacts (PDF)"
                        icon="o-users"
                        link="{{ route('events.equipment.reports.owner-contacts-pdf', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Owner Contacts (CSV)"
                        icon="o-table-cells"
                        link="{{ route('events.equipment.reports.owner-contacts-csv', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Incident Report (PDF)"
                        icon="o-exclamation-triangle"
                        link="{{ route('events.equipment.reports.incident-report-pdf', ['event' => $event->id]) }}"
                    />
                    <x-menu-item
                        title="Incident Report (CSV)"
                        icon="o-table-cells"
                        link="{{ route('events.equipment.reports.incident-report-csv', ['event' => $event->id]) }}"
                    />
                </x-dropdown>
            @endif

            <x-button
                label="Back to Event"
                icon="o-arrow-left"
                class="btn-ghost"
                link="{{ route('events.show', ['event' => $event->id]) }}"
                wire:navigate
            />
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <x-card class="bg-base-200 cursor-pointer hover:bg-base-300 transition-colors" wire:click="$set('statusFilter', null)">
            <div class="text-center">
                <div class="text-3xl font-bold text-primary">{{ $this->statsCards['total'] }}</div>
                <div class="text-sm text-base-content/60">Total</div>
            </div>
        </x-card>

        <x-card class="bg-info/10 cursor-pointer hover:bg-info/20 transition-colors" wire:click="$set('statusFilter', 'committed')">
            <div class="text-center">
                <div class="text-3xl font-bold text-info">{{ $this->statsCards['committed'] }}</div>
                <div class="text-sm text-base-content/60 flex items-center justify-center">
                    <x-icon name="o-clipboard-document-list" class="w-4 h-4 mr-2" />
                    Committed
                </div>
            </div>
        </x-card>

        <x-card class="bg-success/10 cursor-pointer hover:bg-success/20 transition-colors" wire:click="$set('statusFilter', 'delivered')">
            <div class="text-center">
                <div class="text-3xl font-bold text-success">{{ $this->statsCards['delivered'] }}</div>
                <div class="text-sm text-base-content/60 flex items-center justify-center">
                    <x-icon name="o-truck" class="w-4 h-4 mr-2" />
                    Delivered
                </div>
            </div>
        </x-card>

        <x-card class="bg-neutral/10 cursor-pointer hover:bg-neutral/20 transition-colors" wire:click="$set('statusFilter', 'returned')">
            <div class="text-center">
                <div class="text-3xl font-bold text-neutral">{{ $this->statsCards['returned'] }}</div>
                <div class="text-sm text-base-content/60 flex items-center justify-center">
                    <x-icon name="o-check-circle" class="w-4 h-4 mr-2" />
                    Returned
                </div>
            </div>
        </x-card>

        <x-card class="bg-error/10 cursor-pointer hover:bg-error/20 transition-colors" wire:click="$set('statusFilter', null); $set('activeTab', 'overview')">
            <div class="text-center">
                <div class="text-3xl font-bold text-error">{{ $this->statsCards['issues'] }}</div>
                <div class="text-sm text-base-content/60">Issues</div>
            </div>
        </x-card>
    </div>

    {{-- Filters --}}
    <x-card shadow>
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <x-input
                    wire:model.live.debounce.300ms="searchQuery"
                    placeholder="Search equipment, make, model, owner..."
                    icon="o-magnifying-glass"
                    clearable
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-select
                    wire:model.live="typeFilter"
                    :options="$this->equipmentTypes"
                    placeholder="All Types"
                    option-value="id"
                    option-label="name"
                    class="w-40"
                />
                <x-select
                    wire:model.live="statusFilter"
                    :options="$this->statusOptions"
                    placeholder="All Statuses"
                    option-value="id"
                    option-label="name"
                    class="w-40"
                />
                <x-select
                    wire:model.live="stationFilter"
                    :options="collect([['id' => 0, 'name' => 'Unassigned']])->concat($this->commitmentsByStation->filter(fn($g) => $g['station_id'] !== null)->map(fn($g) => ['id' => $g['station_id'], 'name' => $g['station_name']]))->toArray()"
                    placeholder="All Stations"
                    option-value="id"
                    option-label="name"
                    class="w-40"
                />
                @if($searchQuery || $typeFilter || $statusFilter || $stationFilter)
                    <x-button
                        label="Clear"
                        icon="o-x-mark"
                        class="btn-ghost btn-sm"
                        wire:click="clearFilters"
                    />
                @endif
            </div>
        </div>
    </x-card>

    {{-- Tabs --}}
    <x-tabs wire:model="activeTab">
        {{-- Tab 1: Overview --}}
        <x-tab name="overview" label="Overview" icon="o-queue-list">
            <div class="mt-6 space-y-6">
                {{-- Equipment List --}}
                <x-card title="All Equipment ({{ $this->filteredCommitments->count() }})" shadow separator>
                    @if($this->filteredCommitments->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>Equipment</th>
                                        <th>Owner</th>
                                        <th>Status</th>
                                        <th>Station</th>
                                        <th>Last Updated</th>
                                        @if($this->canManage)
                                            <th class="text-right">Actions</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->filteredCommitments as $commitment)
                                        <tr wire:key="commitment-{{ $commitment->id }}" class="hover:bg-base-200/50">
                                            {{-- Equipment Info --}}
                                            <td>
                                                <div class="flex items-center gap-3">
                                                    @if($commitment->equipment->photo_path)
                                                        <img
                                                            src="{{ asset('storage/' . $commitment->equipment->photo_path) }}"
                                                            alt="Equipment"
                                                            class="w-10 h-10 object-cover rounded"
                                                        />
                                                    @else
                                                        <div class="w-10 h-10 bg-base-300 rounded flex items-center justify-center">
                                                            <x-icon name="o-wrench-screwdriver" class="w-5 h-5 text-base-content/50" />
                                                        </div>
                                                    @endif
                                                    <div>
                                                        <div class="font-semibold">{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                                                        <div class="text-xs text-base-content/60">{{ ucfirst(str_replace('_', ' ', $commitment->equipment->type)) }}</div>
                                                    </div>
                                                </div>
                                            </td>

                                            {{-- Owner --}}
                                            <td>
                                                @if($commitment->equipment->owner_organization_id)
                                                    <div class="flex items-center gap-2">
                                                        <x-icon name="o-building-office" class="w-4 h-4 text-base-content/60" />
                                                        <span>{{ $commitment->equipment->owningOrganization->name ?? 'Club' }}</span>
                                                    </div>
                                                @elseif($commitment->equipment->owner)
                                                    <div>
                                                        <div class="font-medium">{{ $commitment->equipment->owner->first_name }} {{ $commitment->equipment->owner->last_name }}</div>
                                                        @if($commitment->equipment->owner->call_sign)
                                                            <div class="text-xs text-base-content/60 font-mono">{{ $commitment->equipment->owner->call_sign }}</div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-base-content/60">Unknown</span>
                                                @endif
                                            </td>

                                            {{-- Status --}}
                                            <td>
                                                @php
                                                    $statusClasses = match($commitment->status) {
                                                        'committed' => 'badge-info',
                                                        'delivered' => 'badge-success',
                                                        'returned' => 'badge-neutral',
                                                        'cancelled' => 'badge-error',
                                                        'lost' => 'badge-error',
                                                        'damaged' => 'badge-error',
                                                        default => 'badge-ghost'
                                                    };
                                                    $statusIcon = match($commitment->status) {
                                                        'committed' => 'o-clipboard-document-list',
                                                        'delivered' => 'o-truck',
                                                        'returned' => 'o-check-circle',
                                                        'cancelled' => 'o-x-circle',
                                                        'lost' => 'o-exclamation-triangle',
                                                        'damaged' => 'o-exclamation-triangle',
                                                        default => 'o-question-mark-circle'
                                                    };
                                                @endphp
                                                <div class="flex items-center gap-2">
                                                    <x-icon name="{{ $statusIcon }}" class="w-4 h-4" />
                                                    <x-badge
                                                        value="{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}"
                                                        class="{{ $statusClasses }}"
                                                    />
                                                </div>
                                            </td>

                                            {{-- Station --}}
                                            <td>
                                                @if($commitment->station)
                                                    <span class="badge badge-primary badge-sm">
                                                        {{ $commitment->station->name }}
                                                        @if($commitment->station->is_gota)
                                                            (GOTA)
                                                        @endif
                                                    </span>
                                                @elseif($primaryRadioStation = $this->primaryRadioStations->get($commitment->equipment_id))
                                                    <span class="badge badge-primary badge-outline badge-sm" title="Primary radio">
                                                        {{ $primaryRadioStation->name }}
                                                        @if($primaryRadioStation->is_gota)
                                                            (GOTA)
                                                        @endif
                                                    </span>
                                                @else
                                                    <span class="text-xs text-base-content/60">Not assigned</span>
                                                @endif
                                            </td>

                                            {{-- Last Updated --}}
                                            <td>
                                                <div class="text-sm">{{ $commitment->status_changed_at?->diffForHumans() ?? '-' }}</div>
                                                @if($commitment->statusChangedBy)
                                                    <div class="text-xs text-base-content/60">by {{ $commitment->statusChangedBy->call_sign ?? $commitment->statusChangedBy->first_name }}</div>
                                                @endif
                                            </td>

                                            {{-- Actions --}}
                                            @if($this->canManage)
                                                <td class="text-right">
                                                    <x-button
                                                        icon="o-arrow-path"
                                                        class="btn-sm btn-ghost"
                                                        wire:click="openStatusModal({{ $commitment->id }})"
                                                        title="Change Status"
                                                    />
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12 text-base-content/60">
                            <x-icon name="o-inbox" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                            <p class="text-lg font-semibold mb-2">No Equipment Found</p>
                            <p class="text-sm">
                                @if($searchQuery || $typeFilter || $statusFilter || $stationFilter)
                                    No equipment matches your current filters.
                                @else
                                    No equipment has been committed to this event yet.
                                @endif
                            </p>
                        </div>
                    @endif
                </x-card>

                {{-- Recent Activity --}}
                <x-card title="Recent Activity" shadow separator>
                    @if($this->recentActivity->count() > 0)
                        <div class="space-y-3">
                            @foreach($this->recentActivity->take(10) as $activity)
                                <div class="flex items-center gap-4 p-2 rounded hover:bg-base-200/50">
                                    <div class="flex-shrink-0">
                                        @php
                                            $statusIcon = match($activity->status) {
                                                'committed' => 'o-clock',
                                                'delivered' => 'o-check-circle',
                                                'returned' => 'o-arrow-uturn-left',
                                                'cancelled' => 'o-x-circle',
                                                'lost' => 'o-exclamation-triangle',
                                                'damaged' => 'o-exclamation-triangle',
                                                default => 'o-question-mark-circle'
                                            };
                                            $statusColor = match($activity->status) {
                                                'committed' => 'text-info',
                                                'delivered' => 'text-success',
                                                'returned' => 'text-neutral',
                                                'cancelled', 'lost', 'damaged' => 'text-error',
                                                default => 'text-base-content/60'
                                            };
                                        @endphp
                                        <x-icon name="{{ $statusIcon }}" class="w-6 h-6 {{ $statusColor }}" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium">
                                            {{ $activity->equipment->make }} {{ $activity->equipment->model }}
                                        </div>
                                        <div class="text-sm text-base-content/60">
                                            Status changed to <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $activity->status)) }}</span>
                                            @if($activity->statusChangedBy)
                                                by {{ $activity->statusChangedBy->call_sign ?? $activity->statusChangedBy->first_name }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-sm text-base-content/60">
                                        {{ $activity->status_changed_at?->diffForHumans() }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-base-content/60">
                            <x-icon name="o-clock" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                            <p>No recent activity</p>
                        </div>
                    @endif
                </x-card>
            </div>
        </x-tab>

        {{-- Tab 2: By Owner --}}
        <x-tab name="by-owner" label="By Owner" icon="o-users">
            <div class="mt-6 space-y-6">
                @if($this->commitmentsByOwner->count() > 0)
                    @foreach($this->commitmentsByOwner as $ownerGroup)
                        <x-card shadow separator>
                            <x-slot:title>
                                <div class="flex items-center gap-2">
                                    @if($ownerGroup['is_club'])
                                        <x-icon name="o-building-office" class="w-5 h-5" />
                                    @else
                                        <x-icon name="o-user" class="w-5 h-5" />
                                    @endif
                                    <span>{{ $ownerGroup['owner_name'] }}</span>
                                    @if($ownerGroup['callsign'])
                                        <span class="font-mono text-sm text-base-content/60">({{ $ownerGroup['callsign'] }})</span>
                                    @endif
                                    <x-badge value="{{ $ownerGroup['count'] }} items" class="badge-ghost" />
                                </div>
                            </x-slot:title>

                            <div class="overflow-x-auto">
                                <table class="table table-compact">
                                    <thead>
                                        <tr>
                                            <th>Equipment</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Station</th>
                                            @if($this->canManage)
                                                <th class="text-right">Actions</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($ownerGroup['items'] as $commitment)
                                            <tr wire:key="owner-{{ $ownerGroup['owner_id'] ?? 'unknown' }}-{{ $commitment->id }}">
                                                <td class="font-medium">
                                                    <div>{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                                                    @if($commitment->equipment->is_club_equipment)
                                                        <div class="mt-1">
                                                            <span class="badge badge-club badge-xs">
                                                                <x-icon name="o-building-office" class="w-3 h-3 mr-0.5" />
                                                                Club Equipment
                                                            </span>
                                                        </div>
                                                        @if($commitment->equipment->managed_by_user_id && $commitment->equipment->manager)
                                                            <div class="text-xs opacity-70 mt-0.5">
                                                                Managed by {{ $commitment->equipment->manager->full_name }}
                                                            </div>
                                                        @endif
                                                    @endif
                                                </td>
                                                <td class="text-sm">{{ ucfirst(str_replace('_', ' ', $commitment->equipment->type)) }}</td>
                                                <td>
                                                    @php
                                                        $statusClasses = match($commitment->status) {
                                                            'committed' => 'badge-info',
                                                            'delivered' => 'badge-success',
                                                            'returned' => 'badge-neutral',
                                                            'cancelled', 'lost', 'damaged' => 'badge-error',
                                                            default => 'badge-ghost'
                                                        };
                                                        $statusIcon = match($commitment->status) {
                                                            'committed' => 'o-clipboard-document-list',
                                                            'delivered' => 'o-truck',
                                                            'returned' => 'o-check-circle',
                                                            'cancelled' => 'o-x-circle',
                                                            'lost' => 'o-exclamation-triangle',
                                                            'damaged' => 'o-exclamation-triangle',
                                                            default => 'o-question-mark-circle'
                                                        };
                                                    @endphp
                                                    <div class="flex items-center gap-2">
                                                        <x-icon name="{{ $statusIcon }}" class="w-4 h-4" />
                                                        <x-badge
                                                            value="{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}"
                                                            class="{{ $statusClasses }} badge-sm"
                                                        />
                                                    </div>
                                                </td>
                                                <td>
                                                    @if($commitment->station)
                                                        {{ $commitment->station->name }}
                                                    @elseif($primaryRadioStation = $this->primaryRadioStations->get($commitment->equipment_id))
                                                        {{ $primaryRadioStation->name }}
                                                    @else
                                                        <span class="text-base-content/50">-</span>
                                                    @endif
                                                </td>
                                                @if($this->canManage)
                                                    <td class="text-right">
                                                        <x-button
                                                            icon="o-arrow-path"
                                                            class="btn-xs btn-ghost"
                                                            wire:click="openStatusModal({{ $commitment->id }})"
                                                            title="Change Status"
                                                        />
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-card>
                    @endforeach
                @else
                    <x-card shadow>
                        <div class="text-center py-12 text-base-content/60">
                            <x-icon name="o-users" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                            <p class="text-lg font-semibold mb-2">No Equipment Committed</p>
                            <p class="text-sm">No equipment has been committed to this event yet.</p>
                        </div>
                    </x-card>
                @endif
            </div>
        </x-tab>

        {{-- Tab 3: By Type --}}
        <x-tab name="by-type" label="By Type" icon="o-tag">
            <div class="mt-6">
                @if($this->equipmentByType->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($this->equipmentByType as $typeGroup)
                            <x-card shadow>
                                <x-slot:title>
                                    <div class="flex items-center justify-between w-full">
                                        <span>{{ $typeGroup['label'] }}</span>
                                        <x-badge value="{{ $typeGroup['count'] }}" class="badge-primary" />
                                    </div>
                                </x-slot:title>

                                <div class="space-y-2">
                                    @foreach($typeGroup['items'] as $commitment)
                                        <div class="flex items-center justify-between p-2 rounded bg-base-200/50">
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium truncate">{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                                                <div class="text-xs text-base-content/60">
                                                    {{ $commitment->equipment->owner_name }}
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0 ml-2">
                                                @php
                                                    $statusClasses = match($commitment->status) {
                                                        'committed' => 'badge-info',
                                                        'delivered' => 'badge-success',
                                                        'returned' => 'badge-neutral',
                                                        'cancelled', 'lost', 'damaged' => 'badge-error',
                                                        default => 'badge-ghost'
                                                    };
                                                    $statusIcon = match($commitment->status) {
                                                        'committed' => 'o-clipboard-document-list',
                                                        'delivered' => 'o-truck',
                                                        'returned' => 'o-check-circle',
                                                        'cancelled' => 'o-x-circle',
                                                        'lost' => 'o-exclamation-triangle',
                                                        'damaged' => 'o-exclamation-triangle',
                                                        default => 'o-question-mark-circle'
                                                    };
                                                @endphp
                                                <div class="flex items-center gap-2">
                                                    <x-icon name="{{ $statusIcon }}" class="w-4 h-4" />
                                                    <x-badge
                                                        value="{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}"
                                                        class="{{ $statusClasses }} badge-sm"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </x-card>
                        @endforeach
                    </div>
                @else
                    <x-card shadow>
                        <div class="text-center py-12 text-base-content/60">
                            <x-icon name="o-tag" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                            <p class="text-lg font-semibold mb-2">No Equipment Committed</p>
                            <p class="text-sm">No equipment has been committed to this event yet.</p>
                        </div>
                    </x-card>
                @endif
            </div>
        </x-tab>

        {{-- Tab 4: By Station --}}
        <x-tab name="by-station" label="By Station" icon="o-building-office">
            <div class="mt-6 space-y-6">
                @if($this->commitmentsByStation->count() > 0)
                    @foreach($this->commitmentsByStation as $stationGroup)
                        <x-card shadow separator>
                            <x-slot:title>
                                <div class="flex items-center gap-2">
                                    @if($stationGroup['station_id'])
                                        <x-icon name="o-building-office" class="w-5 h-5" />
                                    @else
                                        <x-icon name="o-inbox" class="w-5 h-5 text-base-content/50" />
                                    @endif
                                    <span>{{ $stationGroup['station_name'] }}</span>
                                    @if($stationGroup['is_gota'])
                                        <x-badge value="GOTA" class="badge-warning badge-sm" />
                                    @endif
                                    <x-badge value="{{ $stationGroup['count'] }} items" class="badge-ghost" />
                                </div>
                            </x-slot:title>

                            <div class="overflow-x-auto">
                                <table class="table table-compact">
                                    <thead>
                                        <tr>
                                            <th>Equipment</th>
                                            <th>Type</th>
                                            <th>Owner</th>
                                            <th>Status</th>
                                            @if($this->canManage)
                                                <th class="text-right">Actions</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($stationGroup['items'] as $commitment)
                                            <tr wire:key="station-{{ $stationGroup['station_id'] ?? 'unassigned' }}-{{ $commitment->id }}">
                                                <td class="font-medium">
                                                    <div>{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                                                    @if($commitment->equipment->is_club_equipment)
                                                        <div class="mt-1">
                                                            <span class="badge badge-club badge-xs">
                                                                <x-icon name="o-building-office" class="w-3 h-3 mr-0.5" />
                                                                Club Equipment
                                                            </span>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="text-sm">{{ ucfirst(str_replace('_', ' ', $commitment->equipment->type)) }}</td>
                                                <td class="text-sm">{{ $commitment->equipment->owner_name }}</td>
                                                <td>
                                                    @php
                                                        $statusClasses = match($commitment->status) {
                                                            'committed' => 'badge-info',
                                                            'delivered' => 'badge-success',
                                                            'returned' => 'badge-neutral',
                                                            'cancelled', 'lost', 'damaged' => 'badge-error',
                                                            default => 'badge-ghost'
                                                        };
                                                        $statusIcon = match($commitment->status) {
                                                            'committed' => 'o-clipboard-document-list',
                                                            'delivered' => 'o-truck',
                                                            'returned' => 'o-check-circle',
                                                            'cancelled' => 'o-x-circle',
                                                            'lost' => 'o-exclamation-triangle',
                                                            'damaged' => 'o-exclamation-triangle',
                                                            default => 'o-question-mark-circle'
                                                        };
                                                    @endphp
                                                    <div class="flex items-center gap-2">
                                                        <x-icon name="{{ $statusIcon }}" class="w-4 h-4" />
                                                        <x-badge
                                                            value="{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}"
                                                            class="{{ $statusClasses }} badge-sm"
                                                        />
                                                    </div>
                                                </td>
                                                @if($this->canManage)
                                                    <td class="text-right">
                                                        <x-button
                                                            icon="o-arrow-path"
                                                            class="btn-xs btn-ghost"
                                                            wire:click="openStatusModal({{ $commitment->id }})"
                                                            title="Change Status"
                                                        />
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-card>
                    @endforeach
                @else
                    <x-card shadow>
                        <div class="text-center py-12 text-base-content/60">
                            <x-icon name="o-building-office" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                            <p class="text-lg font-semibold mb-2">No Equipment Committed</p>
                            <p class="text-sm">No equipment has been committed to this event yet.</p>
                        </div>
                    </x-card>
                @endif
            </div>
        </x-tab>
    </x-tabs>

    {{-- Status Change Modal --}}
    <x-modal wire:model="showStatusModal" title="Change Equipment Status" class="backdrop-blur">
        @if($statusChangeCommitmentId)
            @php
                $commitment = $this->allCommitments->firstWhere('id', $statusChangeCommitmentId);
            @endphp
            @if($commitment)
                <div class="space-y-4">
                    <div class="p-4 bg-base-200 rounded-lg">
                        <div class="font-semibold">{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                        <div class="text-sm text-base-content/60">Current status: <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}</span></div>
                    </div>

                    @php
                        $availableStatuses = collect(\App\Models\EquipmentEvent::STATUSES)
                            ->reject(fn ($s) => $s === $commitment->status)
                            ->map(fn ($s) => ['id' => $s, 'name' => ucfirst(str_replace('_', ' ', $s))])
                            ->values()
                            ->toArray();
                    @endphp

                    @if(count($availableStatuses) > 0)
                        <x-select
                            label="New Status"
                            wire:model.live="newStatus"
                            :options="$availableStatuses"
                            option-value="id"
                            option-label="name"
                            placeholder="Select new status..."
                        />

                        <x-textarea
                            label="Notes (optional)"
                            wire:model="statusChangeNotes"
                            placeholder="Add any notes about this status change..."
                            rows="3"
                        />
                    @else
                        <div class="text-center py-4 text-base-content/60">
                            <x-icon name="o-no-symbol" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                            <p>No status transitions available from current status.</p>
                        </div>
                    @endif
                </div>

                <x-slot:actions>
                    <x-button
                        label="Cancel"
                        wire:click="$set('showStatusModal', false)"
                        class="btn-ghost"
                    />
                    @if(count($availableStatuses) > 0)
                        <x-button
                            label="Update Status"
                            wire:click="confirmStatusChange"
                            class="btn-primary"
                            spinner="confirmStatusChange"
                            :disabled="!$newStatus"
                        />
                    @endif
                </x-slot:actions>
            @endif
        @endif
    </x-modal>

    {{-- Commit Club Equipment Modal --}}
    <x-modal wire:model="showCommitModal" title="Commit Club Equipment" class="backdrop-blur">
        <x-form wire:submit="commitClubEquipment" class="space-y-4">
            @if($this->availableClubEquipment->count() > 0)
                <x-select
                    label="Equipment"
                    wire:model="commitEquipmentId"
                    icon="o-wrench-screwdriver"
                    placeholder="Select club equipment..."
                    :options="$this->availableClubEquipment->map(fn($eq) => [
                        'id' => $eq->id,
                        'name' => $eq->make . ' ' . $eq->model . ' (' . ucfirst(str_replace('_', ' ', $eq->type)) . ')'
                    ])->toArray()"
                    option-value="id"
                    option-label="name"
                />

                <x-datetime
                    label="Expected Delivery (optional)"
                    wire:model="commitExpectedDeliveryAt"
                    icon="o-calendar"
                />

                <x-textarea
                    label="Delivery Notes (optional)"
                    wire:model="commitDeliveryNotes"
                    placeholder="Add any notes about delivery..."
                    rows="3"
                />
            @else
                <div class="text-center py-4 text-base-content/60">
                    <x-icon name="o-check-circle" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                    <p>All club equipment is already committed to this event.</p>
                </div>
            @endif

            <x-slot:actions>
                <x-button
                    label="Cancel"
                    wire:click="$set('showCommitModal', false)"
                    class="btn-ghost"
                />
                @if($this->availableClubEquipment->count() > 0)
                    <x-button
                        label="Commit Equipment"
                        type="submit"
                        class="btn-primary"
                        spinner="commitClubEquipment"
                    />
                @endif
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- Hidden Test Elements --}}
    <div style="display: none;">
        <span data-testid="event-id">{{ $event->id }}</span>
        <span data-testid="total-commitments">{{ $this->statsCards['total'] }}</span>
        <span data-testid="active-tab">{{ $activeTab }}</span>
        @foreach($this->filteredCommitments as $commitment)
            <span class="test-commitment" data-commitment-id="{{ $commitment->id }}" data-status="{{ $commitment->status }}">
                {{ $commitment->equipment->make }} {{ $commitment->equipment->model }}
            </span>
        @endforeach
    </div>
</div>
