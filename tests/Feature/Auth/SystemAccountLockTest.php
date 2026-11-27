<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Users\UserManagement;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true', 'updated_at' => now()]);
    config(['auth-security.system_account_locked' => true]);

    $this->system = User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN, 'email' => 'admin@localhost']);
    $this->system->assignRole('Config Only');

    $this->admin = User::factory()->create(['call_sign' => 'K1ABC']);
    $this->admin->assignRole('System Administrator');
});

test('a locked SYSTEM cannot sign in with a password', function (string $login) {
    $this->post('/login', ['email' => $login, 'password' => 'password']);

    $this->assertGuest();
})->with(['callsign' => 'SYSTEM', 'email' => 'admin@localhost']);

test('an unlocked SYSTEM can sign in with a password', function () {
    config(['auth-security.system_account_locked' => false]);

    $this->post('/login', ['email' => 'SYSTEM', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->system);
});

test('nobody else can edit or manage a locked SYSTEM', function () {
    expect(Gate::forUser($this->admin)->allows('update', $this->system))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('manageAccount', $this->system))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', User::factory()->create()))->toBeTrue();
});

test('the user list leaves out a locked SYSTEM', function () {
    $this->actingAs($this->admin);

    $ids = Livewire::test(UserManagement::class)->instance()->users()->pluck('id');

    expect($ids)->toContain($this->admin->id)->not->toContain($this->system->id);
});

test('the user list shows an unlocked SYSTEM', function () {
    config(['auth-security.system_account_locked' => false]);
    $this->actingAs($this->admin);

    $ids = Livewire::test(UserManagement::class)->instance()->users()->pluck('id');

    expect($ids)->toContain($this->system->id);
});
