<div>
    <x-slot:title>User Management</x-slot:title>

    <div class="p-4 md:p-6">
        {{-- Header --}}
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Users</h1>
            <x-button label="Add User" icon="o-user-plus" class="btn-primary" wire:click="openCreateModal" responsive />
        </div>

        {{-- Search and Filters --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <x-input
                label="Search"
                placeholder="Search by call sign, name, or email..."
                wire:model.live.debounce.300ms="search"
                icon="o-magnifying-glass"
                clearable
            />

            <x-select
                label="Role"
                wire:model.live="roleFilter"
                :options="[['name' => null, 'id' => null, 'label' => 'All Roles'], ...$this->roles->map(fn($r) => ['name' => $r->name, 'id' => $r->name, 'label' => $r->name])->toArray()]"
                option-value="name"
                option-label="label"
            />

            <x-select
                label="Status"
                wire:model.live="statusFilter"
                :options="[
                    ['value' => null, 'label' => 'All'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'locked', 'label' => 'Locked'],
                    ['value' => '2fa_enabled', 'label' => '2FA Enabled'],
                    ['value' => 'password_reset_required', 'label' => 'Password Reset Required'],
                ]"
                option-value="value"
                option-label="label"
            />
        </div>

        {{-- Bulk Actions Toolbar --}}
        @if(count($selectedUsers) > 0)
            @include('livewire.users.partials.bulk-actions-bar')
        @endif

        {{-- Users Table (Desktop) --}}
        <div class="hidden md:block card bg-base-100 shadow-xl overflow-visible">
            <div class="overflow-visible">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>
                                <x-checkbox wire:model.live="selectAll" />
                            </th>
                            <th>Call Sign</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role(s)</th>
                            <th>License</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->users as $user)
                            <tr wire:key="user-desktop-{{ $user->id }}">
                                <td>
                                    <x-checkbox wire:model.live="selectedUsers" value="{{ $user->id }}" />
                                </td>
                                <td class="font-semibold">{{ $user->call_sign }}</td>
                                <td>{{ $user->first_name }} {{ $user->last_name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @foreach($user->roles as $role)
                                        <span class="badge badge-primary badge-sm">{{ $role->name }}</span>
                                    @endforeach
                                </td>
                                <td>{{ $user->license_class ?? '-' }}</td>
                                <td>
                                    <div class="flex flex-col gap-1">
                                        @if($user->account_locked_at)
                                            <span class="badge badge-error badge-sm">
                                                <x-icon name="o-lock-closed" class="w-3 h-3 mr-1" />
                                                Locked
                                            </span>
                                        @else
                                            <span class="badge badge-success badge-sm">
                                                <x-icon name="o-check-circle" class="w-3 h-3 mr-1" />
                                                Active
                                            </span>
                                        @endif

                                        @if($user->requires_password_change)
                                            <span class="badge badge-warning badge-sm">
                                                <x-icon name="o-key" class="w-3 h-3 mr-1" />
                                                Reset Required
                                            </span>
                                        @endif

                                        @if($user->two_factor_secret)
                                            <span class="badge badge-info badge-sm">
                                                <x-icon name="o-shield-check" class="w-3 h-3 mr-1" />
                                                2FA
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <x-dropdown>
                                        <x-slot:trigger>
                                            <x-button icon="o-ellipsis-vertical" class="btn-sm btn-ghost" />
                                        </x-slot:trigger>

                                        <x-menu-item title="Edit" icon="o-pencil" wire:click="openEditModal({{ $user->id }})" />
                                        <x-menu-separator />

                                        @if($user->account_locked_at)
                                            <x-menu-item title="Unlock Account" icon="o-lock-open" wire:click="unlockAccount({{ $user->id }})" />
                                        @else
                                            <x-menu-item title="Lock Account" icon="o-lock-closed" wire:click="openLockModal({{ $user->id }})" />
                                        @endif

                                        <x-menu-item title="Force Password Reset" icon="o-key" wire:click="forcePasswordReset({{ $user->id }})" />
                                        <x-menu-item title="Reset Password" icon="o-key" wire:click="openResetModal({{ $user->id }})" />
                                        <x-menu-separator />
                                        <x-menu-item title="Delete" icon="o-trash" class="text-error" wire:click="openDeleteModal({{ $user->id }})" />
                                    </x-dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-user-group" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                    <p>No users found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="p-4">
                {{ $this->users->links() }}
            </div>
        </div>

        {{-- Users Cards (Mobile) --}}
        <div class="md:hidden space-y-3">
            @forelse($this->users as $user)
                <div wire:key="user-mobile-{{ $user->id }}" class="card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-3 min-w-0">
                                <x-checkbox wire:model.live="selectedUsers" value="{{ $user->id }}" class="mt-1" />
                                <div class="min-w-0">
                                    <div class="font-bold text-lg">{{ $user->call_sign }}</div>
                                    <div class="text-sm text-base-content/70">{{ $user->first_name }} {{ $user->last_name }}</div>
                                    <div class="text-sm text-base-content/50 truncate">{{ $user->email }}</div>
                                </div>
                            </div>
                            <x-dropdown>
                                <x-slot:trigger>
                                    <x-button icon="o-ellipsis-vertical" class="btn-sm btn-ghost" />
                                </x-slot:trigger>

                                <x-menu-item title="Edit" icon="o-pencil" wire:click="openEditModal({{ $user->id }})" />
                                <x-menu-separator />

                                @if($user->account_locked_at)
                                    <x-menu-item title="Unlock Account" icon="o-lock-open" wire:click="unlockAccount({{ $user->id }})" />
                                @else
                                    <x-menu-item title="Lock Account" icon="o-lock-closed" wire:click="openLockModal({{ $user->id }})" />
                                @endif

                                <x-menu-item title="Force Password Reset" icon="o-key" wire:click="forcePasswordReset({{ $user->id }})" />
                                <x-menu-item title="Reset Password" icon="o-key" wire:click="openResetModal({{ $user->id }})" />
                                <x-menu-separator />
                                <x-menu-item title="Delete" icon="o-trash" class="text-error" wire:click="openDeleteModal({{ $user->id }})" />
                            </x-dropdown>
                        </div>

                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @foreach($user->roles as $role)
                                <span class="badge badge-primary badge-sm">{{ $role->name }}</span>
                            @endforeach

                            @if($user->license_class)
                                <span class="badge badge-outline badge-sm">{{ $user->license_class }}</span>
                            @endif

                            @if($user->account_locked_at)
                                <span class="badge badge-error badge-sm">
                                    <x-icon name="o-lock-closed" class="w-3 h-3 mr-1" />
                                    Locked
                                </span>
                            @else
                                <span class="badge badge-success badge-sm">
                                    <x-icon name="o-check-circle" class="w-3 h-3 mr-1" />
                                    Active
                                </span>
                            @endif

                            @if($user->requires_password_change)
                                <span class="badge badge-warning badge-sm">
                                    <x-icon name="o-key" class="w-3 h-3 mr-1" />
                                    Reset Required
                                </span>
                            @endif

                            @if($user->two_factor_secret)
                                <span class="badge badge-info badge-sm">
                                    <x-icon name="o-shield-check" class="w-3 h-3 mr-1" />
                                    2FA
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="card bg-base-100 shadow">
                    <div class="card-body items-center text-center py-8 text-base-content/60">
                        <x-icon name="o-user-group" class="w-12 h-12 mb-2 opacity-50" />
                        <p>No users found</p>
                    </div>
                </div>
            @endforelse

            {{-- Pagination --}}
            <div class="pt-2">
                {{ $this->users->links() }}
            </div>
        </div>
    </div>

    {{-- Create/Edit User Modal --}}
    @include('livewire.users.partials.user-modal')

    {{-- Lock Account Modal --}}
    @include('livewire.users.partials.lock-modal')

    {{-- Reset Password Modal --}}
    @include('livewire.users.partials.reset-password-modal')

    {{-- Delete Confirmation Modal --}}
    @include('livewire.users.partials.delete-modal')
</div>
