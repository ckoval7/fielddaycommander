<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Settings\RoleManager;
use App\Livewire\Settings\SettingsPage;
use App\Livewire\Settings\SiteBranding;
use App\Livewire\Settings\SystemPreferences;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-roles']);
    Permission::create(['name' => 'manage-settings']);
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
// Authorization
// =============================================================================

test('user without manage-roles cannot mount the component', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(RoleManager::class)
        ->assertForbidden();
});

test('user without manage-roles cannot save permissions', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);
    $component = Livewire::test(RoleManager::class)
        ->call('selectRole', $role->id)
        ->set('selectedPermissions', ['manage-users']);

    $this->actingAs(User::factory()->create());

    $component->call('savePermissions')
        ->assertForbidden();

    expect($role->fresh()->hasPermissionTo('manage-users'))->toBeFalse();
});

test('user without manage-roles cannot create or delete roles', function () {
    $role = Role::create(['name' => 'Doomed Role', 'guard_name' => 'web']);
    $deleteComponent = Livewire::test(RoleManager::class)
        ->call('confirmDelete', $role->id);
    $createComponent = Livewire::test(RoleManager::class)
        ->set('roleName', 'Sneaky Role');

    $this->actingAs(User::factory()->create());

    $deleteComponent->call('deleteRole')->assertForbidden();
    $createComponent->call('createRole')->assertForbidden();

    expect(Role::where('name', 'Doomed Role')->exists())->toBeTrue();
    expect(Role::where('name', 'Sneaky Role')->exists())->toBeFalse();
});

test('manage-settings alone does not grant role management', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-settings');

    $this->actingAs($user);

    Livewire::test(RoleManager::class)->assertForbidden();
});

test('a user holding only manage-roles sees only the role manager in settings', function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSeeLivewire(RoleManager::class)
        ->assertDontSeeLivewire(SystemPreferences::class)
        ->assertDontSeeLivewire(SiteBranding::class);

    Livewire::test(SettingsPage::class)->assertSet('activeTab', 'roles');
});

test('a user holding only manage-roles cannot use the system settings components', function () {
    Livewire::test(SystemPreferences::class)->assertForbidden();
    Livewire::test(SiteBranding::class)->assertForbidden();
});

test('the System Administrator role cannot lose manage-roles', function () {
    $systemAdmin = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $systemAdmin->givePermissionTo(['manage-roles', 'log-contacts']);

    Livewire::test(RoleManager::class)
        ->call('selectRole', $systemAdmin->id)
        ->set('selectedPermissions', ['log-contacts'])
        ->call('savePermissions')
        ->assertDispatched('notify', description: 'The System Administrator role must keep the Manage Roles permission.');

    expect($systemAdmin->fresh()->hasPermissionTo('manage-roles'))->toBeTrue();
});

test('the Manage Roles permission is no longer labelled unused', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('selectRole', $role->id)
        ->assertSee('Manage Roles')
        ->assertDontSee('Manage Roles (Unused)');
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

// =============================================================================
// Role descriptions
// =============================================================================

test('creating a role saves its description', function () {
    Livewire::test(RoleManager::class)
        ->set('roleName', 'Safety Crew')
        ->set('roleDescription', '  Walks the site and fills in the safety checklist  ')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::findByName('Safety Crew')->description)
        ->toBe('Walks the site and fills in the safety checklist');
});

test('a blank description is stored as null', function () {
    Livewire::test(RoleManager::class)
        ->set('roleName', 'Quiet Role')
        ->set('roleDescription', '   ')
        ->call('createRole');

    expect(Role::findByName('Quiet Role')->description)->toBeNull();
});

test('editing a role updates its description', function () {
    $role = Role::unguarded(fn () => Role::create(['name' => 'Test Role', 'guard_name' => 'web', 'description' => 'Old text']));

    Livewire::test(RoleManager::class)
        ->call('openEditModal', $role->id)
        ->assertSet('roleDescription', 'Old text')
        ->assertSet('showEditModal', true)
        ->set('roleDescription', 'New text')
        ->call('updateRole')
        ->assertHasNoErrors()
        ->assertSet('showEditModal', false)
        ->assertDispatched('notify');

    expect($role->fresh()->description)->toBe('New text');

    $auditLog = AuditLog::where('action', 'role.updated')->first();
    expect($auditLog->old_values['description'])->toBe('Old text')
        ->and($auditLog->new_values['description'])->toBe('New text');
});

test('role descriptions are limited to 500 characters', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);

    Livewire::test(RoleManager::class)
        ->call('openEditModal', $role->id)
        ->set('roleDescription', str_repeat('a', 501))
        ->call('updateRole')
        ->assertHasErrors(['roleDescription' => 'max']);

    expect($role->fresh()->description)->toBeNull();
});

test('the roles list and selected role show the description', function () {
    $role = Role::unguarded(fn () => Role::create(['name' => 'Test Role', 'guard_name' => 'web', 'description' => 'Keeps the generator fed']));

    Livewire::test(RoleManager::class)
        ->assertSee('Keeps the generator fed')
        ->call('selectRole', $role->id)
        ->assertSeeHtml('aria-label="Edit description for Test Role"');
});

test('user without manage-settings cannot edit a role description', function () {
    $role = Role::create(['name' => 'Test Role', 'guard_name' => 'web']);
    $component = Livewire::test(RoleManager::class)
        ->call('openEditModal', $role->id)
        ->set('roleDescription', 'Sneaky');

    $this->actingAs(User::factory()->create());

    $component->call('updateRole')->assertForbidden();

    expect($role->fresh()->description)->toBeNull();
});
