<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
    {{-- Left: Roles Table --}}
    <div class="lg:col-span-2">
        <x-card>
            <x-slot:title>Roles</x-slot:title>
            <x-slot:menu>
                <x-button wire:click="openCreateModal" class="btn-primary btn-sm" icon="o-plus">
                    Create New Role
                </x-button>
            </x-slot:menu>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Permissions</th>
                            <th>Users</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $role)
                            <tr
                                wire:click="selectRole({{ $role->id }})"
                                class="cursor-pointer hover:bg-base-200 {{ $selectedRoleId === $role->id ? 'bg-base-300' : '' }}"
                                wire:key="role-{{ $role->id }}"
                            >
                                <td>
                                    <div class="font-medium">{{ $role->name }}</div>
                                    @if($role->name === 'System Administrator')
                                        <span class="badge badge-sm badge-warning">System Protected</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $role->permissions_count }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $role->users_count }}</span>
                                </td>
                                <td>
                                    <x-button
                                        wire:click.stop="confirmDelete({{ $role->id }})"
                                        class="btn-ghost btn-xs text-error"
                                        icon="o-trash"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    {{-- Right: Permission Assignment --}}
    <div class="lg:col-span-3">
        @if($selectedRole)
            <x-card>
                <x-slot:title>{{ $selectedRole->name }}</x-slot:title>
                <x-slot:subtitle>
                    {{ $selectedRole->users_count }} {{ Str::plural('user', $selectedRole->users_count) }} assigned
                </x-slot:subtitle>

                <div class="space-y-4">
                    @foreach($categories as $category => $categoryPermissions)
                        <div class="collapse collapse-arrow bg-base-200">
                            <input type="checkbox" checked />
                            <div class="collapse-title font-medium flex items-center justify-between">
                                <span>{{ $category }}</span>
                                <label class="label cursor-pointer gap-2" onclick="event.stopPropagation()">
                                    <span class="label-text text-xs">Select All</span>
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        wire:change="toggleCategory('{{ $category }}', $event.target.checked)"
                                        @checked(empty(array_diff($categoryPermissions, $selectedPermissions)))
                                    />
                                </label>
                            </div>
                            <div class="collapse-content">
                                <div class="space-y-2 pl-4">
                                    @foreach($permissions->whereIn('name', $categoryPermissions) as $permission)
                                        <label class="label cursor-pointer justify-start gap-3">
                                            <input
                                                type="checkbox"
                                                class="checkbox checkbox-sm"
                                                value="{{ $permission->name }}"
                                                wire:model.live="selectedPermissions"
                                            />
                                            <div>
                                                <div class="font-medium text-sm">{{ $permission->name }}</div>
                                                @if($permission->description)
                                                    <div class="text-xs text-gray-600">{{ $permission->description }}</div>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-600">
                        {{ count($selectedPermissions) }} {{ Str::plural('permission', count($selectedPermissions)) }} selected
                    </div>
                    <x-button
                        wire:click="savePermissions"
                        class="btn-primary"
                        icon="o-check"
                        spinner="savePermissions"
                    >
                        <span wire:loading.remove wire:target="savePermissions">Save Permissions</span>
                        <span wire:loading wire:target="savePermissions">Saving...</span>
                    </x-button>
                </div>
            </x-card>
        @else
            <x-card>
                <div class="text-center py-12 text-gray-500">
                    Select a role to manage permissions
                </div>
            </x-card>
        @endif
    </div>

    {{-- Create Role Modal --}}
    <x-modal wire:model="showCreateModal" title="Create New Role">
        <div class="space-y-4">
            <x-input
                label="Role Name"
                wire:model="roleName"
                required
                hint="Unique name for this role"
            />

            <x-textarea
                label="Description"
                wire:model="roleDescription"
                rows="2"
                hint="Optional description of this role's purpose"
            />

            <div>
                <label class="label">
                    <span class="label-text">Initial Permissions (optional)</span>
                </label>
                <div class="space-y-2 max-h-48 overflow-y-auto border rounded p-2">
                    @foreach($permissions as $permission)
                        <label class="label cursor-pointer justify-start gap-2" wire:key="perm-{{ $permission->id }}">
                            <input
                                type="checkbox"
                                class="checkbox checkbox-sm"
                                value="{{ $permission->name }}"
                                wire:model="initialPermissions"
                            />
                            <span class="text-sm">{{ $permission->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button wire:click="$set('showCreateModal', false)" class="btn-ghost">
                Cancel
            </x-button>
            <x-button
                wire:click="createRole"
                class="btn-primary"
                spinner="createRole"
            >
                <span wire:loading.remove wire:target="createRole">Create Role</span>
                <span wire:loading wire:target="createRole">Creating...</span>
            </x-button>
        </x-slot:actions>
    </x-modal>

    {{-- Delete Confirmation Modal --}}
    <x-modal wire:model="showDeleteModal" title="Delete Role">
        <p>Are you sure you want to delete this role? This action cannot be undone.</p>

        <x-slot:actions>
            <x-button wire:click="$set('showDeleteModal', false)" class="btn-ghost">
                Cancel
            </x-button>
            <x-button
                wire:click="deleteRole"
                class="btn-error"
                spinner="deleteRole"
            >
                <span wire:loading.remove wire:target="deleteRole">Delete Role</span>
                <span wire:loading wire:target="deleteRole">Deleting...</span>
            </x-button>
        </x-slot:actions>
    </x-modal>
</div>
