<div>
    <x-slot:title>Audit Logs</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold">Audit Logs</h1>
                <p class="text-base-content/60 mt-1">View security and activity logs across the system</p>
            </div>
            <x-button
                label="Export CSV"
                icon="phosphor-download-simple"
                class="btn-primary"
                wire:click="exportCsv"
                spinner="exportCsv"
            />
        </div>

        {{-- Filter Section --}}
        <x-card class="mb-6">
            <x-slot:title>Filters</x-slot:title>

            <div class="space-y-4">
                {{-- Row 1: User, Action Type, Date From, Date To --}}
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-choices-offline
                        label="User"
                        wire:model.live="filters.user_ids"
                        :options="$users"
                        placeholder="All Users"
                        option-value="id"
                        option-label="call_sign"
                        searchable
                    />

                    <x-choices-offline
                        label="Action Type"
                        wire:model.live="filters.action_types"
                        :options="$actionTypeGroups"
                        placeholder="All Actions"
                        option-value="value"
                        option-label="label"
                        option-sub-label="group"
                        searchable
                    />

                    <x-flatpickr
                        label="Date From"
                        wire:model.live="filters.date_from"
                        mode="date"
                        icon="phosphor-calendar"
                    />

                    <x-flatpickr
                        label="Date To"
                        wire:model.live="filters.date_to"
                        mode="date"
                        icon="phosphor-calendar"
                    />
                </div>

                {{-- Row 2: IP Address --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <x-input
                        label="IP Address"
                        wire:model.live.debounce.300ms="filters.ip_address"
                        placeholder="Search by IP..."
                        icon="phosphor-globe"
                        clearable
                    />
                </div>

                {{-- Row 3: Date Presets and Action Buttons --}}
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="flex gap-2">
                        <x-button
                            label="Last 24 Hours"
                            wire:click="setDatePreset('24h')"
                            class="btn-sm"
                        />
                        <x-button
                            label="Last 7 Days"
                            wire:click="setDatePreset('7d')"
                            class="btn-sm"
                        />
                        <x-button
                            label="Last 30 Days"
                            wire:click="setDatePreset('30d')"
                            class="btn-sm"
                        />
                    </div>
                    <div class="flex gap-2 ml-auto">
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-sm btn-ghost"
                            icon="phosphor-x"
                        />
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Data Table --}}
        <x-card>
            <div class="overflow-x-auto">
                @php
                    $headers = [
                        ['key' => 'created_at', 'label' => 'Time', 'class' => 'w-48'],
                        ['key' => 'user', 'label' => 'User'],
                        ['key' => 'action', 'label' => 'Action'],
                        ['key' => 'ip_address', 'label' => 'IP Address', 'class' => 'w-40'],
                    ];
                @endphp

                <x-table
                    :headers="$headers"
                    :rows="$logs"
                    with-pagination
                    :per-page-values="[25, 50, 100, 250]"
                    per-page="perPage"
                >
                    @scope('cell_created_at', $log)
                        <div class="text-sm">
                            {{ $log->created_at->format('M j, g:i A') }}
                        </div>
                    @endscope

                    @scope('cell_user', $log)
                        @if($log->user)
                            <div class="font-medium">{{ $log->user->call_sign }}</div>
                            <div class="text-xs text-base-content/60">{{ $log->user->first_name }} {{ $log->user->last_name }}</div>
                        @else
                            <span class="text-base-content/40">—</span>
                        @endif
                    @endscope

                    @scope('cell_action', $log)
                        <div class="flex items-center gap-2">
                            <span>{{ $log->action_label }}</span>
                            @if($log->is_critical)
                                <x-badge value="Critical" class="badge-error badge-sm" />
                            @endif
                        </div>
                    @endscope

                    @scope('cell_ip_address', $log)
                        <code class="text-xs">{{ $log->ip_address }}</code>
                    @endscope

                    @scope('actions', $log)
                        <x-button
                            icon="phosphor-eye"
                            wire:click="showDetails({{ $log->id }})"
                            class="btn-sm btn-ghost"
                            spinner="showDetails"
                        />
                    @endscope
                </x-table>
            </div>

            {{-- Pagination Info --}}
            <div class="p-4 border-t border-base-300">
                <div class="flex justify-between items-center text-sm text-base-content/60">
                    <div>
                        Showing {{ $logs->firstItem() ?? 0 }} to {{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} entries
                    </div>
                    <div class="flex items-center gap-2">
                        <span>Per page:</span>
                        <x-select
                            wire:model.live="perPage"
                            :options="[
                                ['value' => 25, 'label' => '25'],
                                ['value' => 50, 'label' => '50'],
                                ['value' => 100, 'label' => '100'],
                                ['value' => 250, 'label' => '250'],
                            ]"
                            option-value="value"
                            option-label="label"
                            class="select-sm w-20"
                        />
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Empty State --}}
        @if($logs->isEmpty())
            <x-card class="mt-6">
                <div class="text-center py-12">
                    <x-icon name="phosphor-file-magnifying-glass" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                    <h3 class="text-lg font-semibold mb-2">No audit logs found</h3>
                    <p class="text-base-content/60">Try adjusting your filters to see more results.</p>
                </div>
            </x-card>
        @endif
    </div>

    {{-- Detail Modal --}}
    <x-modal wire:model="showDetailModal" title="Audit Log Details" class="backdrop-blur">
        @if($this->selectedLog)
            <div class="space-y-4">
                {{-- Critical Security Event Alert --}}
                @if($this->selectedLog->is_critical)
                    <x-alert
                        title="Critical Security Event"
                        description="This action has been flagged as a critical security event requiring attention."
                        icon="phosphor-warning"
                        class="alert-error"
                    />
                @endif

                {{-- Log Details --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="text-sm font-medium text-base-content/60">Action</div>
                        <div class="text-base font-semibold">{{ $this->selectedLog->action_label }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-base-content/60">Timestamp</div>
                        <div class="text-base">{{ $this->selectedLog->created_at->format('F j, Y g:i:s A') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-base-content/60">User</div>
                        @if($this->selectedLog->user)
                            <div class="text-base">
                                <span class="font-semibold">{{ $this->selectedLog->user->call_sign }}</span>
                                <br>
                                <span class="text-sm text-base-content/60">{{ $this->selectedLog->user->first_name }} {{ $this->selectedLog->user->last_name }}</span>
                            </div>
                        @else
                            <div class="text-base text-base-content/40">System</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-base-content/60">IP Address</div>
                        <div class="text-base">
                            <code class="bg-base-200 px-2 py-1 rounded text-sm">{{ $this->selectedLog->ip_address }}</code>
                        </div>
                    </div>
                </div>

                {{-- User Agent Details --}}
                @if($this->selectedLog->user_agent)
                    <div>
                        <div class="text-sm font-medium text-base-content/60 mb-2">Browser & Device</div>
                        <div class="bg-base-200 p-3 rounded">
                            @php
                                $parsedAgent = $this->selectedLog->parsed_user_agent;
                            @endphp
                            <div class="text-sm space-y-1">
                                <div><span class="font-medium">Browser:</span> {{ $parsedAgent['browser'] }}</div>
                                <div><span class="font-medium">Platform:</span> {{ $parsedAgent['os'] }}</div>
                                <div><span class="font-medium">User Agent:</span> <code class="text-xs">{{ Str::limit($this->selectedLog->user_agent, 80) }}</code></div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Changes (Old vs New Values) --}}
                @if($this->selectedLog->old_values || $this->selectedLog->new_values)
                    <div>
                        <div class="text-sm font-medium text-base-content/60 mb-2">Changes</div>
                        <div class="bg-base-200 p-3 rounded">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-xs font-semibold text-error mb-1">OLD VALUES</div>
                                    <pre class="text-xs overflow-x-auto">{{ json_encode($this->selectedLog->old_values, JSON_PRETTY_PRINT) }}</pre>
                                </div>
                                <div>
                                    <div class="text-xs font-semibold text-success mb-1">NEW VALUES</div>
                                    <pre class="text-xs overflow-x-auto">{{ json_encode($this->selectedLog->new_values, JSON_PRETTY_PRINT) }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Description --}}
                @if($this->selectedLog->description)
                    <div>
                        <div class="text-sm font-medium text-base-content/60 mb-2">Description</div>
                        <div class="bg-base-200 p-3 rounded text-sm">
                            {{ $this->selectedLog->description }}
                        </div>
                    </div>
                @endif
            </div>

            <x-slot:actions>
                <x-button
                    label="Close"
                    @click="$wire.showDetailModal = false"
                    class="btn-ghost"
                />
            </x-slot:actions>
        @endif
    </x-modal>
</div>
