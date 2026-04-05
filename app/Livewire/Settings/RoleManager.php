<?php

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManager extends Component
{
    public ?int $selectedRoleId = null;

    public array $selectedPermissions = [];

    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public bool $showDeleteModal = false;

    public ?int $roleToDelete = null;

    // Create/Edit form fields
    public string $roleName = '';

    public string $roleDescription = '';

    public array $initialPermissions = [];

    protected array $categories = [
        'Contact Logging' => ['log-contacts', 'edit-contacts'],
        'Event Management' => ['view-events', 'create-events', 'edit-events', 'delete-events', 'manage-bulletins', 'verify-bonuses'],
        'Station & Equipment' => ['view-stations', 'manage-stations', 'manage-equipment', 'manage-own-equipment', 'view-all-equipment', 'manage-event-equipment', 'edit-any-equipment'],
        'User Administration' => ['manage-users', 'manage-roles', 'manage-settings'],
        'Content Management' => ['sign-guestbook', 'manage-guestbook', 'manage-shifts', 'manage-images'],
        'Reporting' => ['view-reports'],
        'Security' => ['view-security-logs'],
    ];

    public function selectRole(int $roleId): void
    {
        $this->selectedRoleId = $roleId;
        $role = Role::findById($roleId);
        $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
    }

    public function savePermissions(): void
    {
        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::findById($this->selectedRoleId);

        // System Administrator protection
        if ($role->name === 'System Administrator' && empty($this->selectedPermissions)) {
            $this->dispatch('notify', title: 'Error', description: 'System Administrator role must have at least one permission.');

            return;
        }

        $oldPermissions = $role->permissions->pluck('name')->toArray();

        $role->syncPermissions($this->selectedPermissions);

        AuditLog::log('role.updated', newValues: [
            'role' => $role->name,
            'permissions' => $this->selectedPermissions,
        ], oldValues: [
            'permissions' => $oldPermissions,
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Permissions updated successfully.');
    }

    public function openCreateModal(): void
    {
        $this->reset(['roleName', 'roleDescription', 'initialPermissions']);
        $this->showCreateModal = true;
    }

    public function createRole(): void
    {
        $this->validate([
            'roleName' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'roleDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $role = Role::create([
            'name' => $this->roleName,
            'guard_name' => 'web',
        ]);

        if (! empty($this->initialPermissions)) {
            $role->givePermissionTo($this->initialPermissions);
        }

        AuditLog::log('role.created', newValues: [
            'role' => $role->name,
            'permissions' => $this->initialPermissions,
        ]);

        $this->showCreateModal = false;
        $this->dispatch('notify', title: 'Success', description: 'Role created successfully.');
    }

    public function confirmDelete(int $roleId): void
    {
        $role = Role::findById($roleId);

        // System Administrator protection
        if ($role->name === 'System Administrator') {
            $this->dispatch('notify', title: 'Error', description: 'System Administrator role cannot be deleted.');

            return;
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            $this->dispatch('notify', title: 'Error', description: "Cannot delete role with {$role->users()->count()} assigned users. Remove users first.");

            return;
        }

        $this->roleToDelete = $roleId;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        if (! $this->roleToDelete) {
            return;
        }

        $role = Role::findById($this->roleToDelete);

        AuditLog::log('role.deleted', oldValues: [
            'role' => $role->name,
        ]);

        $role->delete();

        $this->showDeleteModal = false;
        $this->roleToDelete = null;
        $this->selectedRoleId = null;

        $this->dispatch('notify', title: 'Success', description: 'Role deleted successfully.');
    }

    public function toggleCategory(string $category, bool $checked): void
    {
        $permissions = $this->categories[$category] ?? [];

        if ($checked) {
            $this->selectedPermissions = array_unique(array_merge($this->selectedPermissions, $permissions));
        } else {
            $this->selectedPermissions = array_diff($this->selectedPermissions, $permissions);
        }
    }

    public function render()
    {
        return view('livewire.settings.role-manager', [
            'roles' => Role::withCount(['permissions', 'users'])->get(),
            'permissions' => Permission::all(),
            'selectedRole' => $this->selectedRoleId ? Role::findById($this->selectedRoleId) : null,
            'categories' => $this->categories,
        ]);
    }
}
