<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Users\UserManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['manage-users', 'manage-roles', 'manage-settings', 'log-contacts'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->adminRole = Role::findOrCreate('System Administrator', 'web');
    $this->adminRole->syncPermissions(['manage-users', 'manage-roles', 'manage-settings', 'log-contacts']);

    $this->configOnlyRole = Role::findOrCreate('Config Only', 'web');
    $this->configOnlyRole->syncPermissions(['manage-users', 'manage-roles', 'manage-settings']);

    // A club's custom role given "Manage Users" on top of normal logging.
    $this->userManagerRole = Role::findOrCreate('User Manager', 'web');
    $this->userManagerRole->syncPermissions(['manage-users', 'log-contacts']);

    $this->operatorRole = Role::findOrCreate('Operator', 'web');
    $this->operatorRole->syncPermissions('log-contacts');

    $this->manager = User::factory()->create(['call_sign' => 'K1MGR']);
    $this->manager->assignRole($this->userManagerRole);

    $this->admin = User::factory()->create(['call_sign' => 'K1ADM']);
    $this->admin->assignRole($this->adminRole);

    $this->operator = User::factory()->create(['call_sign' => 'K1OPR']);
    $this->operator->assignRole($this->operatorRole);

    $this->system = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);
    $this->system->assignRole($this->configOnlyRole);
});

function editUserAs(User $actor, User $target, int $roleId, ?string $callSign = null)
{
    return Livewire::actingAs($actor)->test(UserManagement::class)
        ->set('editingUserId', $target->id)
        ->set('call_sign', $callSign ?? $target->call_sign)
        ->set('first_name', $target->first_name)
        ->set('last_name', $target->last_name)
        ->set('email', $target->email)
        ->set('role_id', $roleId)
        ->call('saveUser');
}

test('manage-users alone cannot promote itself to System Administrator', function () {
    editUserAs($this->manager, $this->manager, $this->adminRole->id)
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'whose permissions you already have'));

    expect($this->manager->fresh()->hasRole('System Administrator'))->toBeFalse();
});

test('manage-users alone cannot bulk-assign System Administrator', function () {
    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('selectedUsers', [$this->manager->id, $this->operator->id])
        ->set('bulk_role_id', $this->adminRole->id)
        ->call('bulkAssignRole');

    expect($this->manager->fresh()->hasRole('System Administrator'))->toBeFalse()
        ->and($this->operator->fresh()->hasRole('System Administrator'))->toBeFalse();
});

test('manage-users alone cannot create a System Administrator', function () {
    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', false)
        ->set('call_sign', 'W1NEW')
        ->set('first_name', 'New')
        ->set('last_name', 'Admin')
        ->set('email', 'new@example.com')
        ->set('role_id', $this->adminRole->id)
        ->set('password', 'Correct-Horse-9')
        ->set('password_confirmation', 'Correct-Horse-9')
        ->call('saveUser');

    expect(User::where('call_sign', 'W1NEW')->exists())->toBeFalse();
});

test('manage-users alone cannot touch a more privileged account', function (string $method, array $setup) {
    $component = Livewire::actingAs($this->manager)->test(UserManagement::class);

    foreach ($setup as $property => $value) {
        $component->set($property, $value === ':admin' ? $this->admin->id : $value);
    }

    $component->call($method, ...($setup === [] ? [$this->admin->id] : []))->assertForbidden();
})->with([
    'edit' => ['openEditModal', []],
    'unlock' => ['unlockAccount', []],
    'require password change' => ['forcePasswordReset', []],
    'lock' => ['lockAccount', ['lockingUserId' => ':admin']],
    'delete' => ['deleteUser', ['deletingUserId' => ':admin']],
    'reset password' => ['resetPassword', ['resettingUserId' => ':admin', 'resetMethod' => 'manual', 'newPassword' => 'Taken-Over-123']],
]);

test('an admin password reset by a lesser manager leaves the password unchanged', function () {
    $original = $this->admin->password;

    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('resettingUserId', $this->admin->id)
        ->set('resetMethod', 'manual')
        ->set('newPassword', 'Taken-Over-123')
        ->call('resetPassword')
        ->assertForbidden();

    expect($this->admin->fresh()->password)->toBe($original)
        ->and(Hash::check('Taken-Over-123', $this->admin->fresh()->password))->toBeFalse();
});

test('manage-users can still manage accounts with no more access than its own', function () {
    editUserAs($this->manager, $this->operator, $this->operatorRole->id, 'K1OPX')
        ->assertHasNoErrors()
        ->assertDispatched('toast', fn ($name, $params) => $params['description'] === 'User updated.');

    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('lockingUserId', $this->operator->id)
        ->call('lockAccount');

    expect($this->operator->fresh()->call_sign)->toBe('K1OPX')
        ->and($this->operator->fresh()->account_locked_at)->not->toBeNull();
});

test('the role list only offers roles the manager may give out', function () {
    $names = Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->instance()->assignableRoles->pluck('name')->all();

    expect($names)->toContain('Operator', 'User Manager')
        ->not->toContain('System Administrator', 'Config Only');
});

test('bulk lock skips accounts with more access and says so', function () {
    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('selectedUsers', [$this->admin->id, $this->operator->id])
        ->call('bulkLockAccounts')
        ->assertDispatched('toast', fn ($name, $params) => $params['description'] === "1 account locked. Accounts with permissions you don't have were skipped.");

    expect($this->admin->fresh()->account_locked_at)->toBeNull()
        ->and($this->operator->fresh()->account_locked_at)->not->toBeNull();
});

test('the Config Only setup account can still create the first System Administrator', function () {
    Livewire::actingAs($this->system)->test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', false)
        ->set('call_sign', 'W1ADM')
        ->set('first_name', 'First')
        ->set('last_name', 'Admin')
        ->set('email', 'first@example.com')
        ->set('role_id', $this->adminRole->id)
        ->set('password', 'Correct-Horse-9')
        ->set('password_confirmation', 'Correct-Horse-9')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect(User::where('call_sign', 'W1ADM')->first()?->hasRole('System Administrator'))->toBeTrue();
});

test('the SYSTEM account callsign cannot be changed', function () {
    editUserAs($this->admin, $this->system, $this->configOnlyRole->id, 'K1SYS')
        ->assertDispatched('toast', fn ($name, $params) => $params['description'] === 'The SYSTEM account callsign cannot be changed.');

    expect($this->system->fresh()->isSystemUser())->toBeTrue();
});

test('even a System Administrator cannot delete or reset the SYSTEM account', function (string $method, array $setup) {
    $component = Livewire::actingAs($this->admin)->test(UserManagement::class);

    foreach ($setup as $property => $value) {
        $component->set($property, $value === ':system' ? $this->system->id : $value);
    }

    $component->call($method, ...($setup === [] ? [$this->system->id] : []))->assertForbidden();
})->with([
    'require password change' => ['forcePasswordReset', []],
    'delete' => ['deleteUser', ['deletingUserId' => ':system']],
    'reset password' => ['resetPassword', ['resettingUserId' => ':system', 'resetMethod' => 'manual', 'newPassword' => 'Taken-Over-123']],
]);

test('manage-users alone cannot lock the SYSTEM account', function () {
    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('selectedUsers', [$this->system->id])
        ->call('bulkLockAccounts')
        ->assertDispatched('toast', fn ($name, $params) => $params['title'] === 'Nothing changed');

    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->set('lockingUserId', $this->system->id)
        ->call('lockAccount')
        ->assertForbidden();

    expect($this->system->fresh()->account_locked_at)->toBeNull();
});

test('the user list hides actions the manager cannot take', function () {
    Livewire::actingAs($this->manager)->test(UserManagement::class)
        ->assertSeeHtml("openEditModal({$this->operator->id})")
        ->assertSeeHtml("openDeleteModal({$this->operator->id})")
        ->assertDontSeeHtml("openEditModal({$this->admin->id})")
        ->assertDontSeeHtml("openResetModal({$this->admin->id})")
        ->assertDontSeeHtml("openDeleteModal({$this->system->id})");
});
