<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Components\DevRoleSwitcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('renders when dev mode is enabled and user is authenticated', function () {
    config(['developer.enabled' => true]);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->assertSee('Role Switcher');
});

it('does not render content when dev mode is disabled', function () {
    config(['developer.enabled' => false]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->assertDontSee('Role Switcher');
});

it('stores role override in session when role is selected', function () {
    config(['developer.enabled' => true]);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->set('role', 'Operator');

    expect(session('dev_role_override'))->toBe('Operator');
});

it('stores callsign override in session', function () {
    config(['developer.enabled' => true]);

    $user = User::factory()->create(['call_sign' => 'W1AW']);

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->set('callSign', 'K2ABC');

    expect(session('dev_callsign_override'))->toBe('K2ABC');
});

it('clears overrides on reset', function () {
    config(['developer.enabled' => true]);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->set('role', 'Operator')
        ->set('callSign', 'K2ABC')
        ->call('resetOverrides');

    expect(session()->has('dev_role_override'))->toBeFalse()
        ->and(session()->has('dev_callsign_override'))->toBeFalse();
});

it('removes role override when empty value is selected', function () {
    config(['developer.enabled' => true]);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->set('role', 'Operator')
        ->set('role', '');

    expect(session()->has('dev_role_override'))->toBeFalse();
});

it('changes gate behavior when role override is active', function () {
    config(['developer.enabled' => true]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Permission::create(['name' => 'manage-users', 'guard_name' => 'web']);
    Permission::create(['name' => 'log-contacts', 'guard_name' => 'web']);

    $adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage-users');

    $operatorRole = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $operatorRole->givePermissionTo('log-contacts');

    $user = User::factory()->create();
    $user->assignRole('Operator');

    $this->actingAs($user);

    // Operator can log contacts but cannot manage users
    expect(Gate::allows('log-contacts'))->toBeTrue();
    expect(Gate::allows('manage-users'))->toBeFalse();

    // Override to System Administrator
    session(['dev_role_override' => 'System Administrator']);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // Clear the cached roles relationship on the authenticated user
    auth()->user()->unsetRelation('roles');

    // Now they can manage users
    expect(Gate::allows('manage-users'))->toBeTrue();
});

it('shows active badge when overrides are set', function () {
    config(['developer.enabled' => true]);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DevRoleSwitcher::class)
        ->assertDontSee('active')
        ->set('role', 'Operator')
        ->assertSee('active');
});

it('does not render in production even when developer mode is configured on', function () {
    config(['developer.enabled' => true]);
    $this->app['env'] = 'production';
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    Livewire::actingAs(User::factory()->create())
        ->test(DevRoleSwitcher::class)
        ->assertDontSee('Role Switcher');
});

it('refuses role overrides in production', function () {
    config(['developer.enabled' => true]);
    $this->app['env'] = 'production';
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);

    Livewire::actingAs(User::factory()->create())
        ->test(DevRoleSwitcher::class)
        ->set('role', 'System Administrator')
        ->assertNotFound();

    expect(session()->has('dev_role_override'))->toBeFalse();
});

it('refuses role overrides when developer mode is disabled', function () {
    config(['developer.enabled' => false]);
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);

    Livewire::actingAs(User::factory()->create())
        ->test(DevRoleSwitcher::class)
        ->set('role', 'System Administrator')
        ->assertNotFound();

    expect(session()->has('dev_role_override'))->toBeFalse();
});

it('ignores a role override in the session for effective roles in production', function () {
    config(['developer.enabled' => true]);
    $this->app['env'] = 'production';
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $user = User::factory()->create();

    $this->actingAs($user);
    session(['dev_role_override' => 'System Administrator']);

    expect($user->fresh()->roles->pluck('name')->all())->not->toContain('System Administrator');
});
