<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Clear permission cache to ensure all permissions are available
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // System Administrator - All permissions
        $systemAdmin = Role::firstOrCreate([
            'name' => 'System Administrator',
        ], [
            'guard_name' => 'web',
        ]);
        $systemAdmin->givePermissionTo(
            Permission::pluck('name')
        );

        // Event Manager
        $eventManager = Role::firstOrCreate([
            'name' => 'Event Manager',
        ], [
            'guard_name' => 'web',
        ]);
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
        ]);

        // Operator (Default)
        $operator = Role::firstOrCreate([
            'name' => 'Operator',
        ], [
            'guard_name' => 'web',
        ]);
        $operator->givePermissionTo([
            'log-contacts',
            'sign-up-shifts',
            'view-stations',
            'manage-own-equipment',
        ]);

        // Station Captain
        $stationCaptain = Role::firstOrCreate([
            'name' => 'Station Captain',
        ], [
            'guard_name' => 'web',
        ]);
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
        $configOnly = Role::firstOrCreate([
            'name' => 'Config Only',
        ], [
            'guard_name' => 'web',
        ]);
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
}
