<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Default descriptions for the built-in roles. They only fill a blank
     * description, so text an admin has edited is kept.
     *
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        'System Administrator' => 'Full access to every feature and setting',
        'Event Manager' => 'Runs events: logging, stations, equipment, shifts, photos, and reports',
        'Station Captain' => 'Runs a station: logs and edits contacts, manages stations and event equipment',
        'Operator' => 'Logs contacts, signs up for shifts, and manages their own equipment',
        'Config Only' => 'Setup account for users, roles, and settings. It can\'t log contacts.',
    ];

    public function run(): void
    {
        // Clear permission cache to ensure all permissions are available
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // System Administrator - All permissions
        $systemAdmin = $this->ensureRole('System Administrator');
        $systemAdmin->givePermissionTo(
            Permission::pluck('name')
        );

        // Event Manager
        $eventManager = $this->ensureRole('Event Manager');
        $eventManager->givePermissionTo([
            'log-contacts',
            'edit-contacts',
            'sign-up-shifts',
            'view-events',
            'create-events',
            'edit-events',
            'delete-events',
            'manage-bulletins',
            'view-stations',
            'manage-stations',
            'manage-equipment',
            'verify-bonuses',
            'view-reports',
            'manage-guestbook',
            'manage-shifts',
            'manage-images',
            'view-security-logs',
            'manage-own-equipment',
            'view-all-equipment',
            'manage-event-equipment',
            'edit-any-equipment',
            'import-contacts',
            'manage-weather',
        ]);

        // Operator (Default)
        $operator = $this->ensureRole('Operator');
        $operator->givePermissionTo([
            'log-contacts',
            'sign-up-shifts',
            'view-stations',
            'manage-own-equipment',
        ]);

        // Station Captain
        $stationCaptain = $this->ensureRole('Station Captain');
        $stationCaptain->givePermissionTo([
            'log-contacts',
            'edit-contacts',
            'sign-up-shifts',
            'view-stations',
            'manage-stations',
            'manage-bulletins',
            'manage-equipment',
            'manage-own-equipment',
            'view-all-equipment',
            'manage-event-equipment',
        ]);

        // Config Only - View and admin permissions only (for SYSTEM bootstrap account)
        $configOnly = $this->ensureRole('Config Only');
        $configOnly->givePermissionTo([
            'manage-users',
            'manage-roles',
            'manage-settings',
            'view-security-logs',
            'view-events',
            'view-reports',
            'view-stations',
            'view-all-equipment',
        ]);

        $this->command->info('Created or updated 5 roles with permissions');
    }

    /**
     * Find or create a built-in role and give it its default description
     * when it doesn't have one yet.
     */
    private function ensureRole(string $name): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        if ($role->description === null && isset(self::DESCRIPTIONS[$name])) {
            $role->forceFill(['description' => self::DESCRIPTIONS[$name]])->save();
        }

        return $role;
    }
}
