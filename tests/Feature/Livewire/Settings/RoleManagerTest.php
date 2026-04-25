<?php

use App\Livewire\Settings\RoleManager;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-roles']);
    $role->givePermissionTo('manage-roles');
    $this->user->assignRole($role);
    $this->actingAs($this->user);

    // Create test permissions
    Permission::create(['name' => 'log-contacts']);
    Permission::create(['name' => 'edit-contacts']);
    Permission::create(['name' => 'manage-users']);
});

test('component can mount', function () {
    Livewire::test(RoleManager::class)
        ->assertStatus(200);
});

test('displays all roles', function () {
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->assertSee('Operator')
        ->assertSee('Station Captain');
});

test('selects role and loads permissions', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);
    $role->givePermissionTo(['log-contacts', 'edit-contacts']);

    Livewire::test(RoleManager::class)
        ->call('selectRole', $role->id)
        ->assertSet('selectedRoleId', $role->id)
        ->assertSet('selectedPermissions', ['log-contacts', 'edit-contacts']);
});

test('saves permissions to role', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('selectRole', $role->id)
        ->set('selectedPermissions', ['log-contacts', 'manage-users'])
        ->call('savePermissions')
        ->assertDispatched('notify');

    $role->refresh();
    expect($role->hasPermissionTo('log-contacts'))->toBeTrue();
    expect($role->hasPermissionTo('manage-users'))->toBeTrue();
});

test('creates new role', function () {
    Livewire::test(RoleManager::class)
        ->set('roleName', 'New Role')
        ->set('roleDescription', 'Test description')
        ->set('initialPermissions', ['log-contacts'])
        ->call('createRole')
        ->assertDispatched('notify');

    expect(Role::where('name', 'New Role')->exists())->toBeTrue();
    $role = Role::findByName('New Role');
    expect($role->hasPermissionTo('log-contacts'))->toBeTrue();
});

test('prevents deleting System Administrator role', function () {
    $systemAdmin = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('confirmDelete', $systemAdmin->id)
        ->assertDispatched('notify');

    expect(Role::where('name', 'System Administrator')->exists())->toBeTrue();
});

test('prevents deleting role with assigned users', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    Livewire::test(RoleManager::class)
        ->call('confirmDelete', $role->id)
        ->assertDispatched('notify');

    expect(Role::where('name', 'Test Role')->exists())->toBeTrue();
});

test('deletes role without users', function () {
    $role = Role::create(['name' => 'Empty Role', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('confirmDelete', $role->id)
        ->call('deleteRole')
        ->assertDispatched('notify');

    expect(Role::where('name', 'Empty Role')->exists())->toBeFalse();
});

// =============================================================================
// Audit Logging
// =============================================================================

test('creating role logs to audit log', function () {
    Livewire::test(RoleManager::class)
        ->set('roleName', 'New Role')
        ->set('initialPermissions', ['log-contacts'])
        ->call('createRole');

    $auditLog = AuditLog::where('action', 'role.created')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->new_values['role'])->toBe('New Role');
    expect($auditLog->new_values['permissions'])->toContain('log-contacts');
});

test('updating role permissions logs to audit log', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');

    Livewire::test(RoleManager::class)
        ->call('selectRole', $role->id)
        ->set('selectedPermissions', ['log-contacts', 'manage-users'])
        ->call('savePermissions');

    $auditLog = AuditLog::where('action', 'role.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->old_values['permissions'])->toBe(['log-contacts']);
    expect($auditLog->new_values['role'])->toBe('Test Role');
    expect($auditLog->new_values['permissions'])->toContain('manage-users');
});

test('deleting role logs to audit log', function () {
    $role = Role::create(['name' => 'Temp Role', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('confirmDelete', $role->id)
        ->call('deleteRole');

    $auditLog = AuditLog::where('action', 'role.deleted')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->old_values['role'])->toBe('Temp Role');
});

test('every seeded permission is exposed in a role-manager category', function () {
    $this->seed(PermissionSeeder::class);

    $reflection = new ReflectionClass(RoleManager::class);
    $categories = $reflection->getDefaultProperties()['categories'];
    $categorized = collect($categories)->flatten()->all();

    $allPermissions = Permission::pluck('name')->all();
    $missing = array_diff($allPermissions, $categorized);

    expect($missing)->toBe([], 'Permissions missing from RoleManager $categories: '.implode(', ', $missing));
});
