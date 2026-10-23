<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role management used to be gated on manage-settings. It now needs
 * manage-roles, so every role that could manage settings keeps role
 * management, and System Administrator always has it. Upgrades don't
 * re-run PermissionSeeder, so the changed descriptions are set here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'manage-roles')
            ->where('guard_name', 'web')
            ->update(['description' => 'Create and delete roles and choose their permissions']);

        Permission::where('name', 'manage-settings')
            ->where('guard_name', 'web')
            ->update(['description' => 'Change system preferences and site branding']);

        $roles = Role::where('guard_name', 'web')
            ->where(function ($query): void {
                $query->where('name', 'System Administrator')
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'manage-settings'));
            })
            ->get();

        // A fresh install has no roles yet; the seeders set everything up.
        if ($roles->isEmpty()) {
            return;
        }

        $manageRoles = Permission::firstOrCreate(
            ['name' => 'manage-roles', 'guard_name' => 'web'],
            ['description' => 'Create and delete roles and choose their permissions'],
        );

        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($manageRoles)) {
                $role->givePermissionTo($manageRoles);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Granting manage-roles is left in place: removing it could take role
        // management away from roles that had it before this migration.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'manage-roles')
            ->where('guard_name', 'web')
            ->update(['description' => 'Not used. Role management requires manage-settings.']);

        Permission::where('name', 'manage-settings')
            ->where('guard_name', 'web')
            ->update(['description' => 'Change system preferences, site branding, roles, and permissions']);
    }
};
