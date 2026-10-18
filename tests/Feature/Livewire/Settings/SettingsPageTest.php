<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Settings\RoleManager;
use App\Livewire\Settings\SettingsPage;
use App\Livewire\Settings\SiteBranding;
use App\Livewire\Settings\SystemPreferences;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );

    $this->user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-settings']);
    $role->givePermissionTo('manage-settings');
    $this->user->assignRole($role);
    $this->actingAs($this->user);
});

test('settings page renders all settings tabs', function () {
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSeeLivewire(SettingsPage::class)
        ->assertSeeLivewire(SystemPreferences::class)
        ->assertSeeLivewire(SiteBranding::class)
        ->assertSeeLivewire(RoleManager::class)
        ->assertSee('System Preferences')
        ->assertSee('Site Branding')
        ->assertSee('Role Management');
});

test('settings page opens on the system preferences tab', function () {
    Livewire::test(SettingsPage::class)
        ->assertSet('activeTab', 'system-preferences')
        ->set('activeTab', 'roles')
        ->assertSet('activeTab', 'roles');
});
