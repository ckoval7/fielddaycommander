<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
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
        'Contact Logging' => ['log-contacts', 'edit-contacts', 'import-contacts'],
        'Event Management' => ['view-events', 'create-events', 'edit-events', 'delete-events', 'manage-bulletins', 'verify-bonuses'],
        'Station & Equipment' => ['view-stations', 'manage-stations', 'manage-equipment', 'manage-own-equipment', 'view-all-equipment', 'manage-event-equipment', 'edit-any-equipment'],
        'User Administration' => ['manage-users', 'manage-roles', 'manage-settings'],
        'Content Management' => ['manage-guestbook', 'manage-shifts', 'sign-up-shifts', 'manage-images'],
        'Weather' => ['manage-weather'],
        'Reporting' => ['view-reports'],
        'Security' => ['view-security-logs'],
    ];

    public function mount(): void
    {
        Gate::authorize('manage-settings');
    }

    public function selectRole(int $roleId): void
    {
        Gate::authorize('manage-settings');

        $this->selectedRoleId = $roleId;
        $role = Role::findById($roleId);
        $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
    }

    public function savePermissions(): void
    {
        Gate::authorize('manage-settings');

        if (! $this->selectedRoleId) {
            return;
        }

        $role = Role::findById($this->selectedRoleId);

        // System Administrator protection
        if ($role->name === 'System Administrator' && empty($this->selectedPermissions)) {
            $this->dispatch('notify', title: 'Error', description: 'Keep at least one permission for the System Administrator role.');

            return;
        }

        // Config Only role protection - permissions are immutable
        if ($role->name === 'Config Only') {
            $this->dispatch('notify', title: 'Error', description: 'Permissions for the Config Only role cannot be changed.');

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

        $this->dispatch('notify', title: 'Success', description: 'Permissions saved.');
    }

    public function openCreateModal(): void
    {
        $this->reset(['roleName', 'roleDescription', 'initialPermissions']);
        $this->showCreateModal = true;
    }

    public function createRole(): void
    {
        Gate::authorize('manage-settings');

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
        $this->dispatch('notify', title: 'Success', description: 'Role created.');
    }

    public function confirmDelete(int $roleId): void
    {
        Gate::authorize('manage-settings');

        $role = Role::findById($roleId);

        // System Administrator protection
        if ($role->name === 'System Administrator') {
            $this->dispatch('notify', title: 'Error', description: 'The System Administrator role cannot be deleted.');

            return;
        }

        if ($role->name === 'Config Only') {
            $this->dispatch('notify', title: 'Error', description: 'The Config Only role cannot be deleted.');

            return;
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            $this->dispatch('notify', title: 'Error', description: "Remove this role from {$role->users()->count()} assigned ".str('user')->plural($role->users()->count()).' before deleting it.');

            return;
        }

        $this->roleToDelete = $roleId;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        Gate::authorize('manage-settings');

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

        $this->dispatch('notify', title: 'Success', description: 'Role deleted.');
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
            'permissionLabels' => [
                'log-contacts' => 'Log Contacts',
                'edit-contacts' => 'Edit Contacts',
                'view-events' => 'View Events',
                'create-events' => 'Create Events',
                'edit-events' => 'Edit Events',
                'delete-events' => 'Delete Events',
                'manage-bulletins' => 'Manage Bulletin Schedule',
                'verify-bonuses' => 'Verify Bonuses',
                'view-stations' => 'View Stations',
                'manage-stations' => 'Manage Stations',
                'manage-equipment' => 'Manage Equipment (Legacy)',
                'manage-own-equipment' => 'Manage Own Equipment',
                'view-all-equipment' => 'View All Equipment',
                'manage-event-equipment' => 'Manage Event Equipment',
                'edit-any-equipment' => 'Edit Any Equipment',
                'manage-users' => 'Manage Users',
                'manage-roles' => 'Manage Roles (Unused)',
                'manage-settings' => 'Manage System Settings',
                'sign-up-shifts' => 'Sign Up for Shifts',
                'manage-guestbook' => 'Manage Guestbook',
                'manage-shifts' => 'Manage Shifts',
                'manage-images' => 'Delete Any Photo',
                'view-reports' => 'View Reports',
                'view-security-logs' => 'View Audit Logs',
                'import-contacts' => 'Import Contacts',
                'manage-weather' => 'Manage Weather',
            ],
            'selectedRole' => $this->selectedRoleId ? Role::findById($this->selectedRoleId) : null,
            'categories' => $this->categories,
        ]);
    }
}
