<?php

use App\Livewire\Users\UserManagement;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    Permission::firstOrCreate(['name' => 'manage-users']);
    Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web'])->givePermissionTo('manage-users');
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('System Administrator');

    $this->ghost = User::factory()->ghost()->create(['call_sign' => 'KD2GHO']);
});

test('admin can activate a ghost account so it can sign in', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openActivateModal', $this->ghost->id)
        ->assertSet('showActivateModal', true)
        ->set('activateEmail', 'Ghost@Example.com')
        ->set('activateFirstName', 'Gene')
        ->set('activateLastName', 'Host')
        ->set('activatePassword', 'temporary-pass-123')
        ->call('activateGhost')
        ->assertHasNoErrors()
        ->assertSet('showActivateModal', false);

    $user = $this->ghost->fresh();

    expect($user->is_ghost)->toBeFalse()
        ->and($user->email)->toBe('ghost@example.com')
        ->and($user->isLocked())->toBeFalse()
        ->and($user->user_role)->toBe('user')
        ->and($user->requires_password_change)->toBeTrue()
        ->and(Hash::check('temporary-pass-123', $user->password))->toBeTrue()
        ->and($user->hasRole('Operator'))->toBeTrue()
        ->and(AuditLog::where('action', 'user.ghost_activated')->exists())->toBeTrue();

    auth()->logout();

    $this->post(route('login'), ['email' => 'ghost@example.com', 'password' => 'temporary-pass-123'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('activation requires a unique email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openActivateModal', $this->ghost->id)
        ->set('activateEmail', 'taken@example.com')
        ->set('activateFirstName', 'Gene')
        ->set('activateLastName', 'Host')
        ->call('activateGhost')
        ->assertHasErrors(['activateEmail' => 'unique']);

    expect($this->ghost->fresh()->is_ghost)->toBeTrue();
});

test('activation is only offered for ghost accounts', function () {
    $this->actingAs($this->admin);
    $real = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->call('openActivateModal', $real->id)
        ->assertNotFound()
        ->assertSet('showActivateModal', false);
});

test('ghost filter lists only ghost accounts', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('statusFilter', 'ghost')
        ->assertSee('KD2GHO')
        ->assertDontSee($this->admin->call_sign);
});
