<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Rename manage-event-config to manage-bulletins
        $manageEventConfig = Permission::where('name', 'manage-event-config')->first();
        if ($manageEventConfig) {
            $manageEventConfig->update([
                'name' => 'manage-bulletins',
                'description' => 'Manage W1AW bulletin schedule',
            ]);
        }

        // Transfer manage-events permission to create-events + edit-events on all roles
        $manageEvents = Permission::where('name', 'manage-events')->first();
        if ($manageEvents) {
            $createEvents = Permission::where('name', 'create-events')->first();
            $editEvents = Permission::where('name', 'edit-events')->first();

            foreach (Role::all() as $role) {
                if ($role->hasPermissionTo('manage-events')) {
                    if ($createEvents) {
                        $role->givePermissionTo($createEvents);
                    }
                    if ($editEvents) {
                        $role->givePermissionTo($editEvents);
                    }
                }
            }

            $manageEvents->delete();
        }

        // Remove activate-events
        Permission::where('name', 'activate-events')->first()?->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Restore activate-events
        Permission::firstOrCreate(
            ['name' => 'activate-events'],
            ['description' => 'Activate or deactivate events']
        );

        // Restore manage-events
        Permission::firstOrCreate(
            ['name' => 'manage-events'],
            ['description' => 'Create and edit Field Day events']
        );

        // Rename manage-bulletins back to manage-event-config
        $manageBulletins = Permission::where('name', 'manage-bulletins')->first();
        if ($manageBulletins) {
            $manageBulletins->update([
                'name' => 'manage-event-config',
                'description' => 'Configure event settings',
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
