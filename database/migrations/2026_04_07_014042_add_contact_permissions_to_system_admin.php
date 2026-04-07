<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::where('name', 'System Administrator')->where('guard_name', 'web')->first();

        if ($role) {
            $role->givePermissionTo(['log-contacts', 'edit-contacts']);
        }
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::where('name', 'System Administrator')->where('guard_name', 'web')->first();

        if ($role) {
            $role->revokePermissionTo(['log-contacts', 'edit-contacts']);
        }
    }
};
