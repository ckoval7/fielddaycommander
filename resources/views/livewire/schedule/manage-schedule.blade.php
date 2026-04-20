<div>
    <x-slot:title>Manage Schedule{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-4 md:p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <x-button
                        icon="phosphor-arrow-left"
                        class="btn-ghost btn-sm"
                        link="{{ route('schedule.index') }}"
                        tooltip="Back to Shift Schedule"
                    />
                    <h1 class="text-2xl md:text-3xl font-bold">Manage Schedule</h1>
                </div>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
        </div>

        @if(!$eventConfig)
            <x-alert icon="phosphor-warning" class="alert-warning">
                No active event configuration found. Please configure an event first.
            </x-alert>
        @else
            {{-- Tabs --}}
            <x-tabs wire:model="activeTab">
                {{-- Tab 1: Shifts --}}
                <x-tab name="shifts" label="Shifts" icon="phosphor-clock">
                    <div class="mt-6 space-y-4">
                        {{-- Action buttons --}}
                        <div class="flex flex-wrap gap-2">
                            <x-button
                                label="Add Shift"
                                icon="phosphor-plus"
                                class="btn-primary btn-sm"
                                wire:click="openShiftModal"
                            />
                            <x-button
                                label="Bulk Create"
                                icon="phosphor-squares-four"
                                class="btn-outline btn-sm"
                                wire:click="openBulkModal"
                            />
                        </div>

                        {{-- Filter Bar --}}
                        @include('livewire.schedule.partials.filter-bar', [
                            'showSearch' => true,
                            'showTimeFilter' => true,
                            'showStatusFilter' => true,
                            'showAvailability' => true,
                            'statuses' => $this->getFilterStatuses(),
                        ])

                        {{-- Shifts list --}}
                        @if($this->shifts->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="phosphor-clock" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                                    @if($this->activeFilterCount > 0)
                                        <p class="text-lg font-medium">No shifts match your filters</p>
                                        <p class="text-sm mb-3">Try adjusting your filters or clearing them.</p>
                                        <button wire:click="resetFilters" class="btn btn-sm btn-outline">Clear filters</button>
                                    @else
                                        <p class="text-lg font-medium">No shifts created yet</p>
                                        <p class="text-sm">Create individual shifts or use bulk creation to get started.</p>
                                    @endif
                                </div>
                            </x-card>
                        @else
                            <div class="space-y-3" wire:loading.class="opacity-50">
                                @foreach($this->shifts as $shift)
                                    <x-card shadow class="overflow-visible">
                                        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                                            {{-- Role & Time --}}
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1">
                                                    @if($shift->shiftRole)
                                                        <span class="badge text-white" style="background-color: {{ $shift->shiftRole->color ?? '#64748b' }}">{{ $shift->shiftRole->name }}</span>
                                                    @endif
                                                    @if(!$shift->is_open)
                                                        <x-badge value="Closed" class="badge-warning badge-sm" />
                                                    @endif
                                                </div>
                                                <div class="text-sm text-base-content/70">
                                                    {{ toLocalTime($shift->start_time)->format('M j, ' . timeFormat()) }} - {{ toLocalTime($shift->end_time)->format(timeFormat() . ' T') }}
                                                </div>
                                                <div class="text-sm text-base-content/50 mt-1">
                                                    Capacity: {{ $shift->assignments->count() }}/{{ $shift->capacity }}
                                                    @if($shift->notes)
                                                        <span class="ml-2">| {{ $shift->notes }}</span>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Assigned users --}}
                                            <div class="flex-1 min-w-0">
                                                @if($shift->assignments->isNotEmpty())
                                                    <div class="space-y-2">
                                                        @foreach($shift->assignments as $assignment)
                                                            <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-2 text-sm">
                                                                <div class="flex flex-wrap items-center gap-1.5">
                                                                    <span>{{ $assignment->user->first_name }} {{ $assignment->user->last_name }}</span>
                                                                    @if($assignment->user->call_sign)
                                                                        <span class="text-base-content/50">({{ $assignment->user->call_sign }})</span>
                                                                    @endif
                                                                    {{-- Status badge --}}
                                                                    @switch($assignment->status)
                                                                        @case('checked_in')
                                                                            <x-badge value="Checked In" class="badge-success badge-sm" />
                                                                            @break
                                                                        @case('checked_out')
                                                                            <x-badge value="Checked Out" class="badge-info badge-sm" />
                                                                            @break
                                                                        @case('no_show')
                                                                            <x-badge value="No Show" class="badge-error badge-sm" />
                                                                            @break
                                                                        @default
                                                                            <x-badge value="Scheduled" class="badge-neutral badge-sm" />
                                                                    @endswitch
                                                                    {{-- Confirmed badge --}}
                                                                    @if($assignment->confirmed_by_user_id)
                                                                        <x-badge value="Confirmed" class="badge-success badge-sm badge-outline" />
                                                                    @endif
                                                                </div>

                                                                {{-- Assignment action buttons --}}
                                                                <div class="flex items-center gap-1 sm:ml-auto">
                                                                    @if($assignment->status === 'scheduled')
                                                                        <x-button
                                                                            icon="phosphor-sign-in"
                                                                            class="btn-ghost btn-xs"
                                                                            wire:click="managerCheckIn({{ $assignment->id }})"
                                                                            tooltip="Check In"
                                                                            wire:confirm="Check in this user?"
                                                                        />
                                                                    @endif
                                                                    @if($assignment->status === 'checked_in')
                                                                        <x-button
                                                                            icon="phosphor-sign-out"
                                                                            class="btn-ghost btn-xs"
                                                                            wire:click="managerCheckOut({{ $assignment->id }})"
                                                                            tooltip="Check Out"
                                                                            wire:confirm="Check out this user?"
                                                                        />
                                                                    @endif
                                                                    @if($assignment->status === 'scheduled' || $assignment->status === 'checked_in')
                                                                        <x-button
                                                                            icon="phosphor-x"
                                                                            class="btn-ghost btn-xs text-error"
                                                                            wire:click="markNoShow({{ $assignment->id }})"
                                                                            tooltip="Mark No-Show"
                                                                            wire:confirm="Mark this user as a no-show?"
                                                                        />
                                                                    @endif
                                                                    @if($shift->end_time->isPast() && $assignment->checked_out_at === null && $assignment->status !== 'no_show')
                                                                        <x-button
                                                                            icon="phosphor-seal-check"
                                                                            class="btn-ghost btn-xs text-success"
                                                                            wire:click="markWorked({{ $assignment->id }})"
                                                                            tooltip="Mark Worked"
                                                                            wire:confirm="Mark this user's shift as fully worked?"
                                                                        />
                                                                    @endif
                                                                    <x-button
                                                                        icon="phosphor-trash"
                                                                        class="btn-ghost btn-xs text-error"
                                                                        wire:click="removeAssignment({{ $assignment->id }})"
                                                                        tooltip="Remove Assignment"
                                                                        wire:confirm="Remove this assignment?"
                                                                    />
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <p class="text-sm text-base-content/40 italic">No assignments</p>
                                                @endif
                                            </div>

                                            {{-- Shift actions --}}
                                            <div class="flex items-center gap-1 shrink-0">
                                                @if($shift->assignments->count() < $shift->capacity)
                                                    <x-button
                                                        icon="phosphor-user-plus"
                                                        class="btn-ghost btn-sm"
                                                        wire:click="openAssignModal({{ $shift->id }})"
                                                        tooltip="Assign User"
                                                    />
                                                @endif
                                                <x-button
                                                    icon="phosphor-pencil-simple"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="openShiftModal({{ $shift->id }})"
                                                    tooltip="Edit Shift"
                                                />
                                                <x-button
                                                    icon="phosphor-trash"
                                                    class="btn-ghost btn-sm text-error"
                                                    wire:click="deleteShift({{ $shift->id }})"
                                                    wire:confirm="Delete this shift{{ $shift->assignments->count() > 0 ? ' and its ' . $shift->assignments->count() . ' assignment(s)' : '' }}?"
                                                    tooltip="Delete Shift"
                                                />
                                            </div>
                                        </div>
                                    </x-card>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-tab>

                {{-- Tab 2: Roles --}}
                <x-tab name="roles" label="Roles" icon="phosphor-tag">
                    <div class="mt-6 space-y-4">
                        <div class="flex flex-wrap gap-2">
                            <x-button
                                label="Add Custom Role"
                                icon="phosphor-plus"
                                class="btn-primary btn-sm"
                                wire:click="openRoleModal"
                            />
                        </div>

                        @if($this->roles->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="phosphor-tag" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                                    <p class="text-lg font-medium">No roles configured</p>
                                    <p class="text-sm">Roles will be seeded automatically when available.</p>
                                </div>
                            </x-card>
                        @else
                            <div class="space-y-3">
                                @foreach($this->roles as $role)
                                    <x-card shadow>
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1">
                                                    @if($role->icon)
                                                        <x-icon :name="$role->icon" class="w-5 h-5" />
                                                    @endif
                                                    <span class="font-semibold">{{ $role->name }}</span>
                                                    @if($role->is_default)
                                                        <x-badge value="Default" class="badge-neutral badge-sm badge-outline" />
                                                    @endif
                                                </div>
                                                @if($role->description)
                                                    <p class="text-sm text-base-content/60">{{ $role->description }}</p>
                                                @endif
                                                <div class="flex flex-col gap-1 mt-1 text-sm text-base-content/50">
                                                    <div class="flex items-center gap-3">
                                                        @if($role->bonus_points)
                                                            <span>Bonus: {{ $role->bonus_points }} pts</span>
                                                            @if($role->getBonusTypeCode())
                                                                <x-badge value="Auto-awarded on confirmation" class="badge-success badge-sm badge-outline" />
                                                            @endif
                                                        @endif
                                                        @if($role->requires_confirmation)
                                                            <x-badge value="Requires Confirmation" class="badge-info badge-sm badge-outline" />
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <x-button
                                                    icon="phosphor-pencil-simple"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="openRoleModal({{ $role->id }})"
                                                    tooltip="Edit Role"
                                                />
                                                <x-button
                                                    icon="phosphor-trash"
                                                    class="btn-ghost btn-sm text-error"
                                                    wire:click="deleteRole({{ $role->id }})"
                                                    wire:confirm="Delete this role? Any shifts without assignments will also be deleted."
                                                    tooltip="Delete Role"
                                                />
                                            </div>
                                        </div>
                                    </x-card>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-tab>

                {{-- Tab 3: Confirmations --}}
                <x-tab name="confirmations" label="Confirmations" icon="phosphor-seal-check">
                    <div class="mt-6 space-y-4">
                        @if($this->pendingConfirmations->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="phosphor-seal-check" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                                    <p class="text-lg font-medium">No pending confirmations</p>
                                    <p class="text-sm">Confirmations appear here when users check in to roles that require confirmation.</p>
                                </div>
                            </x-card>
                        @else
                            <div class="space-y-3">
                                @foreach($this->pendingConfirmations as $confirmation)
                                    <x-card shadow>
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <span class="font-semibold">
                                                        {{ $confirmation->user->first_name }} {{ $confirmation->user->last_name }}
                                                    </span>
                                                    @if($confirmation->user->call_sign)
                                                        <span class="text-base-content/50">({{ $confirmation->user->call_sign }})</span>
                                                    @endif
                                                </div>
                                                <div class="flex flex-col gap-1 text-sm text-base-content/60">
                                                    <div class="flex items-center gap-2">
                                                        @if($confirmation->shift?->shiftRole)
                                                            <span class="badge badge-sm text-white" style="background-color: {{ $confirmation->shift->shiftRole->color ?? '#64748b' }}">{{ $confirmation->shift->shiftRole->name }}</span>
                                                        @endif
                                                        @if($confirmation->checked_in_at)
                                                            <span>Checked in: {{ toLocalTime($confirmation->checked_in_at)->format('M j, ' . timeFormat() . ' T') }}</span>
                                                        @endif
                                                    </div>
                                                    @if($confirmation->shift?->shiftRole?->getBonusTypeCode())
                                                        <span class="text-xs text-success">Confirming will auto-award {{ $confirmation->shift->shiftRole->bonus_points }} bonus points</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <x-button
                                                    label="Confirm"
                                                    icon="phosphor-check"
                                                    class="btn-success btn-sm"
                                                    wire:click="confirmCheckIn({{ $confirmation->id }})"
                                                />
                                                <x-button
                                                    label="Reject"
                                                    icon="phosphor-x"
                                                    class="btn-error btn-sm btn-outline"
                                                    wire:click="revokeConfirmation({{ $confirmation->id }})"
                                                    wire:confirm="Reject this confirmation?"
                                                />
                                            </div>
                                        </div>
                                    </x-card>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-tab>
            </x-tabs>
        @endif
    </div>

    {{-- Role Form Modal --}}
    <x-modal wire:model="showRoleModal" title="{{ $editingRoleId ? 'Edit Role' : 'Add Custom Role' }}">
        <div>
            <div class="space-y-4">
                <x-input
                    label="Role Name"
                    wire:model="roleName"
                    placeholder="e.g., Band Captain"
                    required
                />
                <x-textarea
                    label="Description"
                    wire:model="roleDescription"
                    placeholder="What does this role do?"
                    rows="2"
                />
                <x-input
                    label="Bonus Points"
                    wire:model="roleBonusPoints"
                    type="number"
                    min="0"
                    placeholder="Leave empty for no bonus"
                    hint="Setting bonus points will auto-enable confirmation requirement"
                />
                <x-toggle
                    label="Requires Confirmation"
                    wire:model="roleRequiresConfirmation"
                    hint="Check-ins must be confirmed by a manager"
                />
                {{-- Icon Picker --}}
                <div x-data="{ selectedIcon: $wire.entangle('roleIcon') }">
                    <label for="role-icon" class="label label-text font-semibold">Icon</label>
                    <div id="role-icon" class="grid grid-cols-6 gap-2 mt-1">
                        @php
                            $iconOptions = [
                                'phosphor-shield-check' => 'Shield',
                                'phosphor-users-three' => 'Group',
                                'phosphor-radio' => 'Radio',
                                'phosphor-graduation-cap' => 'Coach',
                                'phosphor-info' => 'Info',
                                'phosphor-hand' => 'Greet',
                                'phosphor-envelope' => 'Message',
                                'phosphor-clipboard-text' => 'Checklist',
                                'phosphor-wrench' => 'Tools',
                                'phosphor-lightning' => 'Power',
                                'phosphor-fire' => 'Fire',
                                'phosphor-megaphone' => 'Announce',
                                'phosphor-map-pin' => 'Location',
                                'phosphor-camera' => 'Photo',
                                'phosphor-truck' => 'Transport',
                                'phosphor-heart' => 'Medical',
                                'phosphor-eye' => 'Watch',
                                'phosphor-star' => 'Star',
                            ];
                        @endphp
                        @foreach($iconOptions as $iconValue => $iconLabel)
                            <button
                                type="button"
                                @click="selectedIcon = '{{ $iconValue }}'"
                                :class="selectedIcon === '{{ $iconValue }}' ? 'border-primary bg-primary/10' : 'border-base-300 hover:border-base-content/30'"
                                class="flex flex-col items-center gap-1 p-2 rounded-lg border-2 transition-colors cursor-pointer"
                                title="{{ $iconLabel }}"
                            >
                                <x-icon :name="$iconValue" class="w-5 h-5" />
                                <span class="text-xs text-base-content/60">{{ $iconLabel }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Color Picker --}}
                <div x-data="{ selectedColor: $wire.entangle('roleColor') }" class="flex items-center gap-3">
                    <label for="role-color" class="label label-text font-semibold">Color</label>
                    <input
                        id="role-color"
                        type="color"
                        x-model="selectedColor"
                        class="w-10 h-10 rounded cursor-pointer border border-base-300"
                    />
                    <span class="badge badge-sm text-white" :style="'background-color: ' + selectedColor">Preview</span>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showRoleModal = false" />
                <x-button label="Save" wire:click="saveRole" class="btn-primary" spinner="saveRole" />
            </x-slot:actions>
        </div>
    </x-modal>

    {{-- Shift Form Modal --}}
    <x-modal wire:model="showShiftModal" title="{{ $editingShiftId ? 'Edit Shift' : 'Add Shift' }}">
        <div>
            <div class="space-y-4">
                <x-select
                    label="Role"
                    wire:model="shiftRoleId"
                    :options="$this->roles"
                    option-value="id"
                    option-label="name"
                    placeholder="Select a role"
                    required
                />
                <x-flatpickr
                    label="Start Time"
                    wire:model="shiftStartTime"
                    required
                    hint="Times are in your local timezone"
                />
                <x-flatpickr
                    label="End Time"
                    wire:model="shiftEndTime"
                    required
                    hint="Times are in your local timezone"
                />
                <x-input
                    label="Capacity"
                    wire:model="shiftCapacity"
                    type="number"
                    min="1"
                    required
                />
                <x-toggle
                    label="Open for Sign-ups"
                    wire:model="shiftIsOpen"
                    hint="Allow users to self-sign-up for this shift"
                />
                <x-textarea
                    label="Notes"
                    wire:model="shiftNotes"
                    placeholder="Optional notes about this shift"
                    rows="2"
                />
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showShiftModal = false" />
                <x-button label="Save" wire:click="saveShift" class="btn-primary" spinner="saveShift" />
            </x-slot:actions>
        </div>
    </x-modal>

    {{-- Bulk Creation Modal --}}
    <x-modal wire:model="showBulkModal" title="Bulk Create Shifts">
        <div>
            <div class="space-y-4">
                <x-select
                    label="Role"
                    wire:model="bulkRoleId"
                    :options="$this->roles"
                    option-value="id"
                    option-label="name"
                    placeholder="Select a role"
                    required
                />
                <x-flatpickr
                    label="Start Time"
                    wire:model="bulkStartTime"
                    required
                    hint="Times are in your local timezone"
                />
                <x-flatpickr
                    label="End Time"
                    wire:model="bulkEndTime"
                    required
                    hint="Times are in your local timezone"
                />
                <x-input
                    label="Shift Duration (minutes)"
                    wire:model="bulkDurationMinutes"
                    type="number"
                    min="15"
                    max="1440"
                    required
                    hint="Each shift will be this many minutes long"
                />
                <x-input
                    label="Capacity per Shift"
                    wire:model="bulkCapacity"
                    type="number"
                    min="1"
                    required
                />
                <x-toggle
                    label="Open for Sign-ups"
                    wire:model="bulkIsOpen"
                    hint="Allow users to self-sign-up for these shifts"
                />
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showBulkModal = false" />
                <x-button label="Create Shifts" wire:click="createBulkShifts" class="btn-primary" spinner="createBulkShifts" />
            </x-slot:actions>
        </div>
    </x-modal>

    {{-- Assignment Modal --}}
    <x-modal wire:model="showAssignModal" title="Assign User to Shift" class="!overflow-visible" box-class="!overflow-visible">
        <div>
            <div class="space-y-4">
                <x-choices-offline
                    label="User"
                    wire:model="assignUserId"
                    :options="$this->users"
                    option-value="id"
                    option-label="name"
                    placeholder="Search for a user..."
                    searchable
                    single
                />
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showAssignModal = false" />
                <x-button label="Assign" wire:click="assignUser" class="btn-primary" spinner="assignUser" />
            </x-slot:actions>
        </div>
    </x-modal>
</div>
