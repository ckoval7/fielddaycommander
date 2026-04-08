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
                                    @if($role->name === 'System Administrator' || $role->name === 'Config Only')
                                        <x-badge value="System Protected" class="badge-warning badge-sm" />
                                    @endif
                                </td>
                                <td>
                                    <x-badge :value="$role->permissions_count" class="badge-soft" />
                                </td>
                                <td>
                                    <x-badge :value="$role->users_count" class="badge-soft" />
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
                        <x-collapse class="bg-base-200" collapse-plus-minus>
                            <x-slot:heading class="font-medium">
                                <div class="flex items-center justify-between w-full pr-4">
                                    <span>{{ $category }}</span>
                                    <span class="flex items-center gap-2 cursor-pointer" @click.stop @keydown.stop>
                                        <x-checkbox
                                            label="Select All"
                                            wire:change="toggleCategory('{{ $category }}', $event.target.checked)"
                                            :checked="empty(array_diff($categoryPermissions, $selectedPermissions))"
                                        />
                                    </span>
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="space-y-2 pl-4">
                                    @foreach($permissions->whereIn('name', $categoryPermissions) as $permission)
                                        <x-checkbox
                                            value="{{ $permission->name }}"
                                            wire:model.live="selectedPermissions"
                                            :label="$permission->name"
                                            :hint="$permission->description"
                                        />
                                    @endforeach
                                </div>
                            </x-slot:content>
                        </x-collapse>
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
                <div class="text-sm font-medium mb-2">Initial Permissions (optional)</div>
                <div class="space-y-2 max-h-48 overflow-y-auto border border-base-300 rounded p-2">
                    @foreach($permissions as $permission)
                        <x-checkbox
                            label="{{ $permission->name }}"
                            value="{{ $permission->name }}"
                            wire:model="initialPermissions"
                            wire:key="perm-{{ $permission->id }}"
                        />
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
