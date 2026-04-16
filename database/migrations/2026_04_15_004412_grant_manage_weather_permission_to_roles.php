<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'manage-weather', 'guard_name' => 'web']);

        foreach (['System Administrator', 'Event Manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo('manage-weather')) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::where('name', 'manage-weather')->where('guard_name', 'web')->first();
        if (! $permission) {
            return;
        }

        foreach (['System Administrator', 'Event Manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->revokePermissionTo($permission);
            }
        }
    }
};
