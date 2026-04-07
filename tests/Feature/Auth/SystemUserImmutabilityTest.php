<?php

use App\Livewire\Settings\RoleManager;
use App\Livewire\Users\UserManagement;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([\Database\Seeders\PermissionSeeder::class]);
    $this->seed([\Database\Seeders\RoleSeeder::class]);

    $this->admin = User::factory()->create();
    $configOnly = Role::where('name', 'Config Only')->first();
    $sysAdmin = Role::where('name', 'System Administrator')->first();
    $this->admin->assignRole($sysAdmin);

    $this->systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
        'first_name' => 'System',
        'last_name' => 'Administrator',
        'email' => 'admin@localhost',
    ]);
    $this->systemUser->assignRole($configOnly);
});

test('cannot change SYSTEM user role via edit', function () {
    $operatorRole = Role::where('name', 'Operator')->first();

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('editingUserId', $this->systemUser->id)
        ->set('call_sign', $this->systemUser->call_sign)
        ->set('first_name', $this->systemUser->first_name)
        ->set('last_name', $this->systemUser->last_name)
        ->set('email', $this->systemUser->email)
        ->set('role_id', $operatorRole->id)
        ->call('saveUser')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'] ?? '', 'SYSTEM account'));

    expect($this->systemUser->fresh()->roles->first()->name)->toBe('Config Only');
});

test('bulk role assign skips SYSTEM user', function () {
    $operatorRole = Role::where('name', 'Operator')->first();

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$this->systemUser->id, $this->admin->id])
        ->set('bulk_role_id', $operatorRole->id)
        ->call('bulkAssignRole');

    // SYSTEM should still be Config Only
    expect($this->systemUser->fresh()->roles->first()->name)->toBe('Config Only');
    // Admin should have been changed
    expect($this->admin->fresh()->roles->first()->name)->toBe('Operator');
});

test('cannot modify Config Only role permissions', function () {
    $this->actingAs($this->admin);

    $configOnlyRole = Role::where('name', 'Config Only')->first();

    Livewire::test(RoleManager::class)
        ->call('selectRole', $configOnlyRole->id)
        ->set('selectedPermissions', ['manage-users'])
        ->call('savePermissions')
        ->assertDispatched('notify', fn ($name, $params) => str_contains($params['description'] ?? '', 'cannot be modified'));

    // Permissions should be unchanged (8 permissions)
    expect($configOnlyRole->fresh()->permissions->count())->toBe(8);
});

test('cannot delete Config Only role', function () {
    $this->actingAs($this->admin);

    $configOnlyRole = Role::where('name', 'Config Only')->first();

    Livewire::test(RoleManager::class)
        ->call('confirmDelete', $configOnlyRole->id)
        ->assertDispatched('notify', fn ($name, $params) => str_contains($params['description'] ?? '', 'cannot be deleted'));
});
