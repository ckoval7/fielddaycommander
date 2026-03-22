<div>
    <x-slot:title>Manage Schedule{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <x-button
                        icon="o-arrow-left"
                        class="btn-ghost btn-sm"
                        link="{{ route('schedule.index') }}"
                        tooltip="Back to Schedule"
                    />
                    <h1 class="text-2xl md:text-3xl font-bold">Manage Schedule</h1>
                </div>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
        </div>

        @if(!$eventConfig)
            <x-alert icon="o-exclamation-triangle" class="alert-warning">
                No active event configuration found. Please configure an event first.
            </x-alert>
        @else
            {{-- Tabs --}}
            <x-tabs wire:model="activeTab">
                {{-- Tab 1: Shifts --}}
                <x-tab name="shifts" label="Shifts" icon="o-clock">
                    <div class="mt-6 space-y-4">
                        {{-- Action buttons --}}
                        <div class="flex flex-wrap gap-2">
                            <x-button
                                label="Add Shift"
                                icon="o-plus"
                                class="btn-primary btn-sm"
                                wire:click="openShiftModal"
                            />
                            <x-button
                                label="Bulk Create"
                                icon="o-squares-plus"
                                class="btn-outline btn-sm"
                                wire:click="openBulkModal"
                            />
                        </div>

                        {{-- Shifts list --}}
                        @if($this->shifts->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-clock" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                                    <p class="text-lg font-medium">No shifts created yet</p>
                                    <p class="text-sm">Create individual shifts or use bulk creation to get started.</p>
                                </div>
                            </x-card>
                        @else
                            <div class="space-y-3">
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
                                                    {{ $shift->start_time->format('M j, g:i A') }} - {{ $shift->end_time->format('g:i A') }}
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
                                                    <div class="space-y-1">
                                                        @foreach($shift->assignments as $assignment)
                                                            <div class="flex items-center gap-2 text-sm">
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

                                                                {{-- Assignment action buttons --}}
                                                                <div class="flex items-center gap-1 ml-auto">
                                                                    @if($assignment->status === 'scheduled')
                                                                        <x-button
                                                                            icon="o-arrow-right-on-rectangle"
                                                                            class="btn-ghost btn-xs"
                                                                            wire:click="managerCheckIn({{ $assignment->id }})"
                                                                            tooltip="Check In"
                                                                            wire:confirm="Check in this user?"
                                                                        />
                                                                    @endif
                                                                    @if($assignment->status === 'checked_in')
                                                                        <x-button
                                                                            icon="o-arrow-left-on-rectangle"
                                                                            class="btn-ghost btn-xs"
                                                                            wire:click="managerCheckOut({{ $assignment->id }})"
                                                                            tooltip="Check Out"
                                                                            wire:confirm="Check out this user?"
                                                                        />
                                                                    @endif
                                                                    @if($assignment->status === 'scheduled' || $assignment->status === 'checked_in')
                                                                        <x-button
                                                                            icon="o-x-mark"
                                                                            class="btn-ghost btn-xs text-error"
                                                                            wire:click="markNoShow({{ $assignment->id }})"
                                                                            tooltip="Mark No-Show"
                                                                            wire:confirm="Mark this user as a no-show?"
                                                                        />
                                                                    @endif
                                                                    <x-button
                                                                        icon="o-trash"
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
                                                <x-button
                                                    icon="o-user-plus"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="openAssignModal({{ $shift->id }})"
                                                    tooltip="Assign User"
                                                />
                                                <x-button
                                                    icon="o-pencil"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="openShiftModal({{ $shift->id }})"
                                                    tooltip="Edit Shift"
                                                />
                                                <x-button
                                                    icon="o-trash"
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
                <x-tab name="roles" label="Roles" icon="o-tag">
                    <div class="mt-6 space-y-4">
                        <div class="flex flex-wrap gap-2">
                            <x-button
                                label="Add Custom Role"
                                icon="o-plus"
                                class="btn-primary btn-sm"
                                wire:click="openRoleModal"
                            />
                        </div>

                        @if($this->roles->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-tag" class="w-12 h-12 mx-auto mb-3 opacity-30" />
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
                                                    @if($role->color)
                                                        <span class="badge badge-sm text-white" style="background-color: {{ $role->color }}">sample</span>
                                                    @endif
                                                    @if($role->is_default)
                                                        <x-badge value="Default" class="badge-neutral badge-sm badge-outline" />
                                                    @endif
                                                </div>
                                                @if($role->description)
                                                    <p class="text-sm text-base-content/60">{{ $role->description }}</p>
                                                @endif
                                                <div class="flex items-center gap-3 mt-1 text-sm text-base-content/50">
                                                    @if($role->bonus_points)
                                                        <span>Bonus: {{ $role->bonus_points }} pts</span>
                                                    @endif
                                                    @if($role->requires_confirmation)
                                                        <x-badge value="Requires Confirmation" class="badge-warning badge-sm badge-outline" />
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <x-button
                                                    icon="o-pencil"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="openRoleModal({{ $role->id }})"
                                                    tooltip="Edit Role"
                                                />
                                                <x-button
                                                    icon="o-trash"
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
                <x-tab name="confirmations" label="Confirmations" icon="o-check-badge">
                    <div class="mt-6 space-y-4">
                        @if($this->pendingConfirmations->isEmpty())
                            <x-card shadow>
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-check-badge" class="w-12 h-12 mx-auto mb-3 opacity-30" />
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
                                                <div class="flex items-center gap-2 text-sm text-base-content/60">
                                                    @if($confirmation->shift?->shiftRole)
                                                        <span class="badge badge-sm text-white" style="background-color: {{ $confirmation->shift->shiftRole->color ?? '#64748b' }}">{{ $confirmation->shift->shiftRole->name }}</span>
                                                    @endif
                                                    @if($confirmation->checked_in_at)
                                                        <span>Checked in: {{ $confirmation->checked_in_at->format('M j, g:i A') }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <x-button
                                                    label="Confirm"
                                                    icon="o-check"
                                                    class="btn-success btn-sm"
                                                    wire:click="confirmCheckIn({{ $confirmation->id }})"
                                                />
                                                <x-button
                                                    label="Reject"
                                                    icon="o-x-mark"
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
                    <label class="label label-text font-semibold">Icon</label>
                    <div class="grid grid-cols-6 gap-2 mt-1">
                        @php
                            $iconOptions = [
                                'o-shield-check' => 'Shield',
                                'o-user-group' => 'Group',
                                'o-radio' => 'Radio',
                                'o-academic-cap' => 'Coach',
                                'o-information-circle' => 'Info',
                                'o-hand-raised' => 'Greet',
                                'o-envelope' => 'Message',
                                'o-clipboard-document-check' => 'Checklist',
                                'o-wrench-screwdriver' => 'Tools',
                                'o-bolt' => 'Power',
                                'o-fire' => 'Fire',
                                'o-megaphone' => 'Announce',
                                'o-map-pin' => 'Location',
                                'o-camera' => 'Photo',
                                'o-truck' => 'Transport',
                                'o-heart' => 'Medical',
                                'o-eye' => 'Watch',
                                'o-star' => 'Star',
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
                    <label class="label label-text font-semibold">Color</label>
                    <input
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
                <x-input
                    label="Start Time"
                    wire:model="shiftStartTime"
                    type="datetime-local"
                    required
                />
                <x-input
                    label="End Time"
                    wire:model="shiftEndTime"
                    type="datetime-local"
                    required
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
                <x-input
                    label="Start Time"
                    wire:model="bulkStartTime"
                    type="datetime-local"
                    required
                />
                <x-input
                    label="End Time"
                    wire:model="bulkEndTime"
                    type="datetime-local"
                    required
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
    <x-modal wire:model="showAssignModal" title="Assign User to Shift">
        <div>
            <div class="space-y-4">
                <x-select
                    label="User"
                    wire:model="assignUserId"
                    placeholder="Select a user"
                    required
                >
                    @foreach($this->users as $user)
                        <option value="{{ $user->id }}">
                            {{ $user->first_name }} {{ $user->last_name }}
                            @if($user->call_sign) ({{ $user->call_sign }}) @endif
                        </option>
                    @endforeach
                </x-select>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showAssignModal = false" />
                <x-button label="Assign" wire:click="assignUser" class="btn-primary" spinner="assignUser" />
            </x-slot:actions>
        </div>
    </x-modal>
</div>
