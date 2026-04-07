<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create sign-up-shifts permission and grant to existing operator roles
        $signUpShifts = Permission::firstOrCreate(['name' => 'sign-up-shifts', 'guard_name' => 'web']);
        foreach (['Operator', 'Station Captain', 'Event Manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo('sign-up-shifts')) {
                $role->givePermissionTo($signUpShifts);
            }
        }

        // Give System Administrator all permissions (was missing log-contacts, edit-contacts)
        $systemAdmin = Role::where('name', 'System Administrator')->where('guard_name', 'web')->first();
        if ($systemAdmin) {
            $systemAdmin->syncPermissions(Permission::pluck('name'));
        }

        // Create Config Only role
        $configOnly = Role::firstOrCreate(
            ['name' => 'Config Only', 'guard_name' => 'web']
        );
        $configOnlyPermissionNames = [
            'manage-users',
            'manage-roles',
            'manage-settings',
            'view-security-logs',
            'view-events',
            'view-reports',
            'view-stations',
            'view-all-equipment',
        ];
        $configOnly->syncPermissions(
            Permission::whereIn('name', $configOnlyPermissionNames)->get()
        );

        // Reassign SYSTEM user from System Administrator to Config Only
        $systemUser = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first();
        if ($systemUser) {
            $systemUser->syncRoles(['Config Only']);
        }
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Reassign SYSTEM user back to System Administrator
        $systemUser = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first();
        if ($systemUser) {
            $systemUser->syncRoles(['System Administrator']);
        }

        // Remove Config Only role
        Role::where('name', 'Config Only')->delete();

        // Restore System Administrator to exclude contact permissions
        $systemAdmin = Role::where('name', 'System Administrator')->where('guard_name', 'web')->first();
        if ($systemAdmin) {
            $systemAdmin->syncPermissions(
                Permission::whereNotIn('name', ['log-contacts', 'edit-contacts'])->pluck('name')
            );
        }
    }
};
