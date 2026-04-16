<div>
    <x-slot:title>Guestbook Manager - {{ $event->name }}</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <x-button
                        icon="phosphor-arrow-left"
                        class="btn-ghost btn-sm"
                        link="{{ route('events.show', $event) }}"
                        tooltip="Back to Event"
                    />
                    <h1 class="text-2xl md:text-3xl font-bold">Guestbook Manager</h1>
                </div>
                <p class="text-base-content/60">{{ $event->name }} - {{ $this->entryStats['total'] }} total entries</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="Export CSV"
                    icon="phosphor-download-simple"
                    class="btn-outline"
                    wire:click="$dispatch('toast', { title: 'Coming Soon', description: 'CSV export will be available in a future update', icon: 'o-information-circle', css: 'alert-info' })"
                />
            </div>
        </div>

        @if(!$eventConfig)
            <x-alert icon="phosphor-warning" class="alert-warning">
                This event does not have an event configuration. Guestbook entries cannot be managed.
            </x-alert>
        @elseif(!$eventConfig->guestbook_enabled)
            <x-alert icon="phosphor-info" class="alert-info">
                The guestbook is currently disabled for this event.
                <x-button
                    label="Enable in Event Settings"
                    class="btn-sm btn-ghost ml-2"
                    link="{{ route('events.edit', ['eventId' => $event->id]) }}"
                />
            </x-alert>
        @else
            {{-- Main Content Layout --}}
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-6">
                {{-- Stats Cards (Left Side) --}}
                <div class="lg:col-span-3 space-y-6">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">Total</div>
                            <div class="stat-value text-2xl">{{ $this->entryStats['total'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">Verified</div>
                            <div class="stat-value text-2xl text-success">{{ $this->entryStats['verified'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">Unverified</div>
                            <div class="stat-value text-2xl text-warning">{{ $this->entryStats['unverified'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">In Person</div>
                            <div class="stat-value text-2xl">{{ $this->entryStats['in_person'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">Online</div>
                            <div class="stat-value text-2xl">{{ $this->entryStats['online'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg shadow">
                            <div class="stat-title">Bonus Eligible</div>
                            <div class="stat-value text-2xl text-info">{{ $this->entryStats['bonus_eligible'] }}</div>
                        </div>
                    </div>
                </div>

                {{-- Bonus Points Sidebar (Right Side) --}}
                <div class="lg:col-span-1">
                    <livewire:guestbook.bonus-points-sidebar :event-config-id="$eventConfig->id" />
                </div>
            </div>

            {{-- Filters --}}
            <x-card class="mb-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-input
                        label="Search"
                        placeholder="Search name, callsign, email..."
                        wire:model.live.debounce.300ms="search"
                        icon="phosphor-magnifying-glass"
                        clearable
                    />

                    <x-select
                        label="Presence"
                        wire:model.live="filterPresence"
                        :options="$this->presenceOptions"
                        option-value="value"
                        option-label="label"
                    />

                    <x-select
                        label="Category"
                        wire:model.live="filterCategory"
                        :options="$this->categoryOptions"
                        option-value="value"
                        option-label="label"
                    />

                    <x-select
                        label="Status"
                        wire:model.live="filterVerified"
                        :options="$this->verifiedOptions"
                        option-value="value"
                        option-label="label"
                    />
                </div>

                @if($search || $filterPresence || $filterCategory || $filterVerified)
                    <div class="mt-4 flex justify-end">
                        <x-button
                            label="Clear Filters"
                            icon="phosphor-x"
                            class="btn-ghost btn-sm"
                            wire:click="clearFilters"
                        />
                    </div>
                @endif
            </x-card>

            {{-- Bulk Actions Bar --}}
            @if(count($selectedIds) > 0)
                @can('manage-guestbook')
                    <x-alert icon="phosphor-check-circle" class="alert-info mb-4">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between w-full gap-4">
                            <span class="font-semibold">{{ count($selectedIds) }} entry(ies) selected</span>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-button
                                    label="Verify"
                                    icon="phosphor-check"
                                    class="btn-sm btn-success"
                                    wire:click="bulkVerify"
                                    spinner="bulkVerify"
                                />
                                <x-button
                                    label="Unverify"
                                    icon="phosphor-x"
                                    class="btn-sm btn-warning"
                                    wire:click="bulkUnverify"
                                    spinner="bulkUnverify"
                                />
                                <x-button
                                    label="Delete"
                                    icon="phosphor-trash"
                                    class="btn-sm btn-error"
                                    wire:click="bulkDelete"
                                    wire:confirm="Are you sure you want to delete {{ count($selectedIds) }} entries? This action cannot be undone."
                                    spinner="bulkDelete"
                                />
                                <x-button
                                    label="Cancel"
                                    class="btn-sm btn-ghost"
                                    wire:click="$set('selectedIds', [])"
                                />
                            </div>
                        </div>
                    </x-alert>
                @endcan
            @endif

            {{-- Entries Table --}}
            <x-card>
                @php
                    $headers = [
                        ['key' => 'name', 'label' => 'Name', 'sortable' => false],
                        ['key' => 'callsign', 'label' => 'Callsign'],
                        ['key' => 'visitor_category', 'label' => 'Category'],
                        ['key' => 'presence_type', 'label' => 'Presence'],
                        ['key' => 'is_verified', 'label' => 'Status', 'sortable' => false],
                        ['key' => 'created_at', 'label' => 'Signed'],
                    ];
                @endphp

                <div class="overflow-x-auto">
                    <x-table
                        :headers="$headers"
                        :rows="$this->entries"
                        :sort-by="$sortBy"
                        wire:model="selectedIds"
                        selectable
                        with-pagination
                    >
                        @scope('cell_name', $entry)
                            <div class="min-w-0">
                                <div class="font-medium truncate">{{ $entry->first_name }} {{ $entry->last_name }}</div>
                                @if($entry->email)
                                    <div class="text-xs text-base-content/60 truncate">{{ $entry->email }}</div>
                                @endif
                            </div>
                        @endscope

                        @scope('cell_callsign', $entry)
                            @if($entry->callsign)
                                <code class="text-sm font-mono">{{ $entry->callsign }}</code>
                            @else
                                <span class="text-base-content/40">-</span>
                            @endif
                        @endscope

                        @scope('cell_visitor_category', $entry)
                            @php
                                $isBonusEligible = in_array($entry->visitor_category, \App\Models\GuestbookEntry::BONUS_ELIGIBLE_CATEGORIES);
                            @endphp
                            <div class="flex items-center gap-1">
                                <x-badge
                                    :value="$this->getCategoryLabel($entry->visitor_category)"
                                    :class="$isBonusEligible ? 'badge-warning badge-sm whitespace-nowrap' : 'badge-neutral badge-sm whitespace-nowrap'"
                                />
                                @if($isBonusEligible && $entry->is_verified)
                                    <span title="Bonus Eligible">+</span>
                                @endif
                            </div>
                        @endscope

                        @scope('cell_presence_type', $entry)
                            @if($entry->presence_type === \App\Models\GuestbookEntry::PRESENCE_TYPE_IN_PERSON)
                                <x-badge value="In Person" class="badge-info badge-sm" />
                            @else
                                <x-badge value="Online" class="badge-ghost badge-sm" />
                            @endif
                        @endscope

                        @scope('cell_is_verified', $entry)
                            @if($entry->is_verified)
                                <div class="flex items-center gap-1 text-success">
                                    <x-icon name="phosphor-check-circle" class="w-4 h-4" />
                                    <span class="text-xs">Verified</span>
                                </div>
                            @else
                                <div class="flex items-center gap-1 text-base-content/50">
                                    <x-icon name="phosphor-clock" class="w-4 h-4" />
                                    <span class="text-xs">Pending</span>
                                </div>
                            @endif
                        @endscope

                        @scope('cell_created_at', $entry)
                            <div class="text-sm">
                                <div>{{ $entry->created_at->format('M j, Y') }}</div>
                                <div class="text-xs text-base-content/60">{{ $entry->created_at->format('g:i A') }}</div>
                            </div>
                        @endscope

                        @scope('actions', $entry)
                            @can('manage-guestbook')
                                <div class="flex items-center gap-1">
                                    <x-button
                                        icon="phosphor-pencil-simple"
                                        class="btn-ghost btn-sm"
                                        wire:click="openVerifyModal({{ $entry->id }})"
                                        spinner="openVerifyModal"
                                        tooltip="Edit/Verify"
                                    />
                                    <x-button
                                        icon="phosphor-trash"
                                        class="btn-ghost btn-sm text-error"
                                        wire:click="openDeleteModal({{ $entry->id }})"
                                        spinner="openDeleteModal"
                                        tooltip="Delete"
                                    />
                                </div>
                            @endcan
                        @endscope
                    </x-table>
                </div>

                {{-- Empty State --}}
                @if($this->entries->isEmpty())
                    <div class="text-center py-12">
                        <x-icon name="phosphor-users-three" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                        <h3 class="text-lg font-semibold mb-2">No entries found</h3>
                        <p class="text-base-content/60">
                            @if($search || $filterPresence || $filterCategory || $filterVerified)
                                Try adjusting your filters to see more results.
                            @else
                                No one has signed the guestbook yet.
                            @endif
                        </p>
                    </div>
                @endif

                {{-- Pagination --}}
                @if($this->entries->hasPages())
                    <div class="p-4 border-t border-base-300">
                        {{ $this->entries->links() }}
                    </div>
                @endif
            </x-card>
        @endif
    </div>

    {{-- Verify/Edit Modal --}}
    <x-modal wire:model="showVerifyModal" title="Edit Entry" class="backdrop-blur">
        @if($this->editingEntry)
            <div class="space-y-4">
                {{-- Entry Info --}}
                <div class="bg-base-200 p-4 rounded-lg">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs text-base-content/60">Name</div>
                            <div class="font-medium">{{ $this->editingEntry->first_name }} {{ $this->editingEntry->last_name }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-base-content/60">Callsign</div>
                            <div class="font-mono">{{ $this->editingEntry->callsign ?: '-' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-base-content/60">Email</div>
                            <div>{{ $this->editingEntry->email ?: '-' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-base-content/60">Presence</div>
                            <div>{{ $this->editingEntry->presence_type === \App\Models\GuestbookEntry::PRESENCE_TYPE_IN_PERSON ? 'In Person' : 'Online' }}</div>
                        </div>
                        <div class="col-span-2">
                            <div class="text-xs text-base-content/60">Signed</div>
                            <div>{{ $this->editingEntry->created_at->format('F j, Y g:i A') }}</div>
                        </div>
                        @if($this->editingEntry->comments)
                            <div class="col-span-2">
                                <div class="text-xs text-base-content/60">Comments</div>
                                <div class="italic">"{{ $this->editingEntry->comments }}"</div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Category Selection --}}
                <x-select
                    label="Visitor Category"
                    wire:model="editCategory"
                    :options="collect($this->categoryOptions)->filter(fn($o) => $o['value'] !== '')->toArray()"
                    option-value="value"
                    option-label="label"
                />

                @php
                    $selectedCategoryIsBonusEligible = in_array($editCategory, \App\Models\GuestbookEntry::BONUS_ELIGIBLE_CATEGORIES);
                @endphp

                @if($selectedCategoryIsBonusEligible)
                    <x-alert icon="phosphor-star" class="alert-warning">
                        This category is <strong>bonus eligible</strong>. Verified entries in this category contribute to Field Day bonus points.
                    </x-alert>
                @endif

                {{-- Verification Toggle --}}
                <x-toggle
                    label="Mark as Verified"
                    wire:model="editVerified"
                    hint="Verified entries are confirmed by event staff"
                />

                @if($this->editingEntry->is_verified && $this->editingEntry->verifiedBy)
                    <div class="text-sm text-base-content/60">
                        Verified by {{ $this->editingEntry->verifiedBy->first_name }} {{ $this->editingEntry->verifiedBy->last_name }}
                        on {{ $this->editingEntry->verified_at->format('M j, Y g:i A') }}
                    </div>
                @endif
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.closeVerifyModal()" class="btn-ghost" />
                <x-button
                    label="Save Changes"
                    wire:click="saveVerification"
                    class="btn-primary"
                    spinner="saveVerification"
                />
            </x-slot:actions>
        @endif
    </x-modal>

    {{-- Delete Confirmation Modal --}}
    <x-modal wire:model="showDeleteModal" title="Delete Entry" class="backdrop-blur">
        <div class="space-y-4">
            <x-alert icon="phosphor-warning" class="alert-error">
                Are you sure you want to delete this guestbook entry? This action cannot be undone.
            </x-alert>

            @if($deletingEntryId)
                @php
                    $deletingEntry = \App\Models\GuestbookEntry::find($deletingEntryId);
                @endphp
                @if($deletingEntry)
                    <div class="bg-base-200 p-4 rounded-lg">
                        <div class="font-medium">{{ $deletingEntry->first_name }} {{ $deletingEntry->last_name }}</div>
                        @if($deletingEntry->callsign)
                            <div class="text-sm text-base-content/60">{{ $deletingEntry->callsign }}</div>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.closeDeleteModal()" class="btn-ghost" />
            <x-button
                label="Delete"
                wire:click="deleteEntry"
                class="btn-error"
                spinner="deleteEntry"
            />
        </x-slot:actions>
    </x-modal>
</div>
