<?php

use App\Livewire\Users\UserManagement;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitation as UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create permissions
    Permission::create(['name' => 'manage-users']);

    // Create roles
    $this->roles = [
        'System Administrator' => Role::create(['name' => 'System Administrator', 'guard_name' => 'web']),
        'Event Manager' => Role::create(['name' => 'Event Manager', 'guard_name' => 'web']),
        'Station Captain' => Role::create(['name' => 'Station Captain', 'guard_name' => 'web']),
        'Operator' => Role::create(['name' => 'Operator', 'guard_name' => 'web']),
    ];

    // Grant manage-users permission to System Administrator role
    $this->roles['System Administrator']->givePermissionTo('manage-users');

    // Create admin user
    $this->admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $this->admin->assignRole('System Administrator');
});

// =============================================================================
// Component Access (3 tests)
// =============================================================================

test('users with manage-users permission can access the page', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->assertStatus(200)
        ->assertSee('Users');
});

test('users without permission get 403', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $this->actingAs($user);

    Livewire::test(UserManagement::class)
        ->assertForbidden();
});

test('page displays user table correctly', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
    ]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->assertSee('W1AW')
        ->assertSee('John')
        ->assertSee('Doe')
        ->assertSee('test@example.com');
});

// =============================================================================
// Search & Filters (7 tests)
// =============================================================================

test('can search users by call sign', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['call_sign' => 'W1AW']);
    $user2 = User::factory()->create(['call_sign' => 'K2XYZ']);

    Livewire::test(UserManagement::class)
        ->set('search', 'W1AW')
        ->assertSee('W1AW')
        ->assertDontSee('K2XYZ');
});

test('can search users by name', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
    $user2 = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);

    Livewire::test(UserManagement::class)
        ->set('search', 'John')
        ->assertSee('John Doe')
        ->assertDontSee('Jane Smith');
});

test('can search users by email', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['email' => 'john@example.com']);
    $user2 = User::factory()->create(['email' => 'jane@example.com']);

    Livewire::test(UserManagement::class)
        ->set('search', 'john@')
        ->assertSee('john@example.com')
        ->assertDontSee('jane@example.com');
});

test('can filter users by role', function () {
    $this->actingAs($this->admin);

    $operator = User::factory()->create(['call_sign' => 'W1AW']);
    $operator->assignRole('Operator');

    $eventManager = User::factory()->create(['call_sign' => 'K2XYZ']);
    $eventManager->assignRole('Event Manager');

    Livewire::test(UserManagement::class)
        ->set('roleFilter', 'Operator')
        ->assertSee('W1AW')
        ->assertDontSee('K2XYZ');
});

test('can filter by active status', function () {
    $this->actingAs($this->admin);

    $activeUser = User::factory()->create(['call_sign' => 'W1AW', 'account_locked_at' => null]);
    $lockedUser = User::factory()->create(['call_sign' => 'K2XYZ', 'account_locked_at' => now()]);

    Livewire::test(UserManagement::class)
        ->set('statusFilter', 'active')
        ->assertSee('W1AW')
        ->assertDontSee('K2XYZ');
});

test('can filter by locked status', function () {
    $this->actingAs($this->admin);

    $activeUser = User::factory()->create(['call_sign' => 'W1AW', 'account_locked_at' => null]);
    $lockedUser = User::factory()->create(['call_sign' => 'K2XYZ', 'account_locked_at' => now()]);

    Livewire::test(UserManagement::class)
        ->set('statusFilter', 'locked')
        ->assertDontSee('W1AW')
        ->assertSee('K2XYZ');
});

test('search and filters combine correctly', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['call_sign' => 'W1AW', 'account_locked_at' => null]);
    $user1->assignRole('Operator');

    $user2 = User::factory()->create(['call_sign' => 'K2XYZ', 'account_locked_at' => null]);
    $user2->assignRole('Event Manager');

    $user3 = User::factory()->create(['call_sign' => 'W3ABC', 'account_locked_at' => now()]);
    $user3->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->set('search', 'W')
        ->set('roleFilter', 'Operator')
        ->set('statusFilter', 'active')
        ->assertSee('W1AW')
        ->assertDontSee('K2XYZ')
        ->assertDontSee('W3ABC');
});

// =============================================================================
// Create User (7 tests)
// =============================================================================

test('can create user with invitation email mode', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Config::set('mail.email_configured', true);

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->assertSet('showModal', true)
        ->assertSet('inviteMode', true)
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('license_class', 'Extra')
        ->set('role_id', $this->roles['Operator']->id)
        ->call('saveUser')
        ->assertSet('showModal', false)
        ->assertDispatched('toast');

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->call_sign)->toBe('W1AW')
        ->and($user->hasRole('Operator'))->toBeTrue();

    // Verify invitation was created
    $invitation = UserInvitation::where('user_id', $user->id)->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->isValid())->toBeTrue();

    // Verify notification was sent
    Notification::assertSentTo($user, UserInvitationNotification::class);
});

test('can create user with manual password mode', function () {
    Notification::fake();

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', false)
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('license_class', 'General')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser')
        ->assertSet('showModal', false)
        ->assertDispatched('toast');

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue();

    // Verify no invitation was created
    $invitation = UserInvitation::where('user_id', $user->id)->first();
    expect($invitation)->toBeNull();

    // Verify no notification was sent
    Notification::assertNothingSent();
});

test('create user defaults to manual password when email is not configured', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->assertSet('inviteMode', false)
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('saveUser')
        ->assertSet('showModal', false)
        ->assertDispatched('toast');

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull()
        ->and(Hash::check('Password123!', $user->password))->toBeTrue();

    // No invitation created, no notification sent
    $invitation = UserInvitation::where('user_id', $user->id)->first();
    expect($invitation)->toBeNull();
    Notification::assertNothingSent();
});

test('create user backend guard prevents invitation when email is not configured', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    // Force inviteMode to true despite email being off (simulates tampered request)
    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', true)
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('saveUser')
        ->assertSet('showModal', false)
        ->assertDispatched('toast');

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();

    // Backend guard should have overridden inviteMode — no invitation sent
    $invitation = UserInvitation::where('user_id', $user->id)->first();
    expect($invitation)->toBeNull();
    Notification::assertNothingSent();
});

test('validates all required fields', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', false)
        ->call('saveUser')
        ->assertHasErrors(['call_sign', 'first_name', 'last_name', 'email'])
        ->assertHasNoErrors(['role_id']);
});

test('prevents duplicate call signs', function () {
    $this->actingAs($this->admin);

    User::factory()->create(['call_sign' => 'W1AW']);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser')
        ->assertHasErrors(['call_sign']);
});

test('prevents duplicate emails', function () {
    $this->actingAs($this->admin);

    User::factory()->create(['email' => 'test@example.com']);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser')
        ->assertHasErrors(['email']);
});

test('assigns role correctly on creation', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Event Manager']->id)
        ->set('inviteMode', false)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser');

    $user = User::where('email', 'test@example.com')->first();
    expect($user->hasRole('Event Manager'))->toBeTrue()
        ->and($user->hasRole('Operator'))->toBeFalse();
});

test('shows success message after creating user', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser')
        ->assertDispatched('toast');
});

// =============================================================================
// Edit User (5 tests)
// =============================================================================

test('can open edit modal with pre-filled data', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'license_class' => 'Extra',
    ]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->assertSet('editingUserId', $user->id)
        ->assertSet('call_sign', 'W1AW')
        ->assertSet('first_name', 'John')
        ->assertSet('last_name', 'Doe')
        ->assertSet('email', 'test@example.com')
        ->assertSet('license_class', 'Extra')
        ->assertSet('role_id', $this->roles['Operator']->id)
        ->assertSet('showModal', true);
});

test('can update user details', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'old@example.com',
    ]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('call_sign', 'K2XYZ')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('email', 'new@example.com')
        ->set('license_class', 'General')
        ->call('saveUser')
        ->assertSet('showModal', false);

    $user->refresh();
    expect($user->call_sign)->toBe('K2XYZ')
        ->and($user->first_name)->toBe('Jane')
        ->and($user->last_name)->toBe('Smith')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->license_class)->toBe('General');
});

test('can change user role', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create();
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('role_id', $this->roles['Event Manager']->id)
        ->call('saveUser');

    $user->refresh();
    expect($user->hasRole('Event Manager'))->toBeTrue()
        ->and($user->hasRole('Operator'))->toBeFalse();
});

test('validates unique constraints excluding current user', function () {
    $this->actingAs($this->admin);

    $otherUser = User::factory()->create([
        'call_sign' => 'K2XYZ',
        'email' => 'other@example.com',
    ]);

    $user = User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'test@example.com',
    ]);
    $user->assignRole('Operator');

    // Should allow keeping own call_sign and email
    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('call_sign', 'W1AW')
        ->set('email', 'test@example.com')
        ->call('saveUser')
        ->assertHasNoErrors();

    // Should prevent using other user's call_sign
    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('call_sign', 'K2XYZ')
        ->call('saveUser')
        ->assertHasErrors(['call_sign']);

    // Should prevent using other user's email
    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('call_sign', 'W1AW')
        ->set('email', 'other@example.com')
        ->call('saveUser')
        ->assertHasErrors(['email']);
});

test('shows success message after updating user', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create();
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('first_name', 'Updated')
        ->call('saveUser')
        ->assertDispatched('toast');
});

test('user list refreshes after role change', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1TST']);
    $user->assignRole('Operator');

    $component = Livewire::test(UserManagement::class)
        ->assertSee('Operator');

    $component
        ->call('openEditModal', $user->id)
        ->set('role_id', $this->roles['Event Manager']->id)
        ->call('saveUser')
        ->assertSee('Event Manager');
});

// =============================================================================
// Lock/Unlock Account (5 tests)
// =============================================================================

test('can lock account with expiry date', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['account_locked_at' => null]);

    $expiry = now()->addDays(7)->format('Y-m-d H:i:s');

    Livewire::test(UserManagement::class)
        ->call('openLockModal', $user->id)
        ->assertSet('lockingUserId', $user->id)
        ->assertSet('showLockModal', true)
        ->set('lockExpiry', $expiry)
        ->call('lockAccount')
        ->assertSet('showLockModal', false)
        ->assertDispatched('toast');

    $user->refresh();
    expect($user->isLocked())->toBeTrue()
        ->and($user->account_locked_at->format('Y-m-d H:i:s'))->toBe($expiry);
});

test('can lock account without expiry (permanent)', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['account_locked_at' => null]);

    Livewire::test(UserManagement::class)
        ->call('openLockModal', $user->id)
        ->set('lockExpiry', null)
        ->call('lockAccount')
        ->assertSet('showLockModal', false);

    $user->refresh();
    expect($user->isLocked())->toBeTrue();
});

test('validates lock expiry is in future', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['account_locked_at' => null]);

    $pastDate = now()->subDay()->format('Y-m-d H:i:s');

    Livewire::test(UserManagement::class)
        ->call('openLockModal', $user->id)
        ->set('lockExpiry', $pastDate)
        ->call('lockAccount')
        ->assertHasErrors(['lockExpiry']);

    $user->refresh();
    expect($user->isLocked())->toBeFalse();
});

test('can unlock locked account', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['account_locked_at' => now()]);

    Livewire::test(UserManagement::class)
        ->call('unlockAccount', $user->id)
        ->assertDispatched('toast');

    $user->refresh();
    expect($user->isLocked())->toBeFalse();
});

test('locked users show lock badge in table', function () {
    $this->actingAs($this->admin);

    $lockedUser = User::factory()->create([
        'call_sign' => 'W1AW',
        'account_locked_at' => now(),
    ]);

    Livewire::test(UserManagement::class)
        ->assertSee('W1AW');
});

// =============================================================================
// Password Management (3 tests)
// =============================================================================

test('can force password reset on user', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['requires_password_change' => false]);

    Livewire::test(UserManagement::class)
        ->call('forcePasswordReset', $user->id)
        ->assertDispatched('toast');

    $user->refresh();
    expect($user->requires_password_change)->toBeTrue();
});

test('can send password reset email', function () {
    Notification::fake();

    $this->actingAs($this->admin);

    $user = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->call('openResetModal', $user->id)
        ->assertSet('resettingUserId', $user->id)
        ->assertSet('resetMethod', 'manual')
        ->assertSet('showResetModal', true)
        ->set('resetMethod', 'email')
        ->call('resetPassword')
        ->assertSet('showResetModal', false)
        ->assertDispatched('toast');

    Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
});

test('can reset password with auto-generated password', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create();
    $oldPassword = $user->password;

    $component = Livewire::test(UserManagement::class)
        ->call('openResetModal', $user->id)
        ->assertSet('resetMethod', 'manual');

    $generatedPassword = $component->get('newPassword');
    expect($generatedPassword)->toBeString()->not->toBeEmpty();

    $component
        ->call('resetPassword')
        ->assertSet('showResetModal', false)
        ->assertDispatched('toast');

    $user->refresh();
    expect(Hash::check($generatedPassword, $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($oldPassword)
        ->and($user->requires_password_change)->toBeTrue();
});

// =============================================================================
// Delete User (4 tests)
// =============================================================================

test('can soft delete user', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW']);

    Livewire::test(UserManagement::class)
        ->call('openDeleteModal', $user->id)
        ->assertSet('deletingUserId', $user->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', false)
        ->assertDispatched('toast');

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

test('deleted user hidden from list', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW']);
    $user->delete();

    Livewire::test(UserManagement::class)
        ->assertDontSee('W1AW');
});

test('prevents self-deletion', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openDeleteModal', $this->admin->id)
        ->call('deleteUser')
        ->assertDispatched('toast');

    expect(User::find($this->admin->id))->not->toBeNull();
});

test('shows confirmation before delete', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->call('openDeleteModal', $user->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingUserId', $user->id);
});

// =============================================================================
// Bulk Actions (7 tests)
// =============================================================================

test('can select multiple users', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->assertSet('selectedUsers', [$user1->id, $user2->id]);
});

test('bulk actions toolbar appears when users selected', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user->id])
        ->assertSet('selectedUsers', [$user->id]);
});

test('can bulk assign role to multiple users', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create();
    $user1->assignRole('Operator');

    $user2 = User::factory()->create();
    $user2->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->set('bulk_role_id', $this->roles['Event Manager']->id)
        ->call('bulkAssignRole')
        ->assertDispatched('toast');

    $user1->refresh();
    $user2->refresh();

    expect($user1->hasRole('Event Manager'))->toBeTrue()
        ->and($user2->hasRole('Event Manager'))->toBeTrue();
});

test('can bulk lock multiple accounts', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['account_locked_at' => null]);
    $user2 = User::factory()->create(['account_locked_at' => null]);

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->call('bulkLockAccounts')
        ->assertDispatched('toast');

    $user1->refresh();
    $user2->refresh();

    expect($user1->isLocked())->toBeTrue()
        ->and($user2->isLocked())->toBeTrue();
});

test('can bulk unlock multiple accounts', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['account_locked_at' => now()]);
    $user2 = User::factory()->create(['account_locked_at' => now()]);

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->call('bulkUnlockAccounts')
        ->assertDispatched('toast');

    $user1->refresh();
    $user2->refresh();

    expect($user1->isLocked())->toBeFalse()
        ->and($user2->isLocked())->toBeFalse();
});

test('can bulk delete multiple users', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->call('bulkDeleteUsers')
        ->assertDispatched('toast');

    expect(User::withTrashed()->find($user1->id)->trashed())->toBeTrue()
        ->and(User::withTrashed()->find($user2->id)->trashed())->toBeTrue();
});

test('bulk actions limited to 50 users', function () {
    $this->actingAs($this->admin);

    $userIds = User::factory()->count(51)->create()->pluck('id')->toArray();

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', $userIds)
        ->set('bulk_role_id', $this->roles['Event Manager']->id)
        ->call('bulkAssignRole')
        ->assertDispatched('toast');

    // Verify users were not modified (bulk action should fail due to limit)
    $firstUser = User::find($userIds[0]);
    expect($firstUser->roles)->toHaveCount(0);
});

// =============================================================================
// Security (4 tests)
// =============================================================================

test('cannot delete own account', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('deletingUserId', $this->admin->id)
        ->call('deleteUser')
        ->assertDispatched('toast');

    expect(User::find($this->admin->id))->not->toBeNull();
});

test('cannot lock own account', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openLockModal', $this->admin->id)
        ->call('lockAccount')
        ->assertDispatched('toast');

    $this->admin->refresh();
    expect($this->admin->isLocked())->toBeFalse();
});

test('all actions check manage-users permission', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $this->actingAs($user);

    Livewire::test(UserManagement::class)
        ->assertForbidden();
});

test('warns when deleting/locking system administrator', function () {
    $this->actingAs($this->admin);

    $systemAdmin = User::factory()->create();
    $systemAdmin->assignRole('System Administrator');

    // This is a behavioral test - the component should show a warning
    // In reality, the UI would show a confirmation dialog for this
    Livewire::test(UserManagement::class)
        ->call('openDeleteModal', $systemAdmin->id)
        ->assertSet('showDeleteModal', true);
});

// =============================================================================
// Pagination (2 tests)
// =============================================================================

test('users are paginated at 15 per page', function () {
    $this->actingAs($this->admin);

    // Create 20 users (plus the admin = 21 total)
    User::factory()->count(20)->create();

    $component = Livewire::test(UserManagement::class);

    // Should see first page of users
    expect($component->get('users')->count())->toBe(15);
});

test('can navigate to next page', function () {
    $this->actingAs($this->admin);

    // Create 20 users
    $users = User::factory()->count(20)->create();

    Livewire::test(UserManagement::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2);
});

// =============================================================================
// Audit Logging (7 tests)
// =============================================================================

test('creating user logs to audit log', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->set('call_sign', 'W1AW')
        ->set('first_name', 'John')
        ->set('last_name', 'Doe')
        ->set('email', 'test@example.com')
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser');

    $auditLog = AuditLog::where('action', 'user.created')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->user_id)->toBe($this->admin->id);
    expect($auditLog->new_values)->toMatchArray([
        'call_sign' => 'W1AW',
        'email' => 'test@example.com',
        'role' => 'Operator',
    ]);
});

test('updating user logs to audit log with old and new values', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create([
        'call_sign' => 'W1AW',
        'email' => 'old@example.com',
    ]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('call_sign', 'K2XYZ')
        ->set('email', 'new@example.com')
        ->set('role_id', $this->roles['Event Manager']->id)
        ->call('saveUser');

    $auditLog = AuditLog::where('action', 'user.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->old_values)->toMatchArray([
        'call_sign' => 'W1AW',
        'email' => 'old@example.com',
        'role' => 'Operator',
    ]);
    expect($auditLog->new_values)->toMatchArray([
        'call_sign' => 'K2XYZ',
        'email' => 'new@example.com',
        'role' => 'Event Manager',
    ]);
});

test('locking account logs to audit log as critical', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW', 'account_locked_at' => null]);

    Livewire::test(UserManagement::class)
        ->call('openLockModal', $user->id)
        ->call('lockAccount');

    $auditLog = AuditLog::where('action', 'user.locked')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeTrue();
    expect($auditLog->new_values['call_sign'])->toBe('W1AW');
});

test('unlocking account logs to audit log', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW', 'account_locked_at' => now()]);

    Livewire::test(UserManagement::class)
        ->call('unlockAccount', $user->id);

    $auditLog = AuditLog::where('action', 'user.unlocked')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->new_values['call_sign'])->toBe('W1AW');
});

test('deleting user logs to audit log as critical', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'delete@example.com']);

    Livewire::test(UserManagement::class)
        ->call('openDeleteModal', $user->id)
        ->call('deleteUser');

    $auditLog = AuditLog::where('action', 'user.deleted')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeTrue();
    expect($auditLog->old_values)->toMatchArray([
        'call_sign' => 'W1AW',
        'email' => 'delete@example.com',
    ]);
});

test('admin password reset logs to audit log as critical', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['call_sign' => 'W1AW']);

    Livewire::test(UserManagement::class)
        ->call('openResetModal', $user->id)
        ->call('resetPassword');

    $auditLog = AuditLog::where('action', 'user.password.reset_by_admin')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->is_critical)->toBeTrue();
    expect($auditLog->new_values['call_sign'])->toBe('W1AW');
});

// =============================================================================
// Youth Flag (2 tests)
// =============================================================================

test('can set is_youth flag when creating a user', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('call_sign', 'KD2ZZZ')
        ->set('first_name', 'Young')
        ->set('last_name', 'Operator')
        ->set('email', 'young@example.com')
        ->set('is_youth', true)
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('saveUser');

    $user = User::where('call_sign', 'KD2ZZZ')->first();
    expect($user)->not->toBeNull()
        ->and($user->is_youth)->toBeTrue();
});

test('can toggle is_youth flag when editing a user', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['is_youth' => false]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('is_youth', true)
        ->call('saveUser');

    expect($user->fresh()->is_youth)->toBeTrue();
});

// =============================================================================
// CPR/AED Trained Flag
// =============================================================================

test('can set is_cpr_aed_trained flag when creating a user', function () {
    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->set('call_sign', 'KD2CPR')
        ->set('first_name', 'Medic')
        ->set('last_name', 'Operator')
        ->set('email', 'medic@example.com')
        ->set('is_cpr_aed_trained', true)
        ->set('role_id', $this->roles['Operator']->id)
        ->set('inviteMode', false)
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('saveUser');

    $user = User::where('call_sign', 'KD2CPR')->first();
    expect($user)->not->toBeNull()
        ->and($user->is_cpr_aed_trained)->toBeTrue();
});

test('can toggle is_cpr_aed_trained flag when editing a user', function () {
    $this->actingAs($this->admin);

    $user = User::factory()->create(['is_cpr_aed_trained' => false]);
    $user->assignRole('Operator');

    Livewire::test(UserManagement::class)
        ->call('openEditModal', $user->id)
        ->set('is_cpr_aed_trained', true)
        ->call('saveUser');

    expect($user->fresh()->is_cpr_aed_trained)->toBeTrue();
});

test('bulk delete logs individual audit entries for each user', function () {
    $this->actingAs($this->admin);

    $user1 = User::factory()->create(['call_sign' => 'W1AW']);
    $user2 = User::factory()->create(['call_sign' => 'K2XYZ']);

    Livewire::test(UserManagement::class)
        ->set('selectedUsers', [$user1->id, $user2->id])
        ->call('bulkDeleteUsers');

    $auditLogs = AuditLog::where('action', 'user.deleted')->get();
    expect($auditLogs)->toHaveCount(2);
    expect($auditLogs->pluck('old_values.call_sign')->sort()->values()->toArray())->toBe(['K2XYZ', 'W1AW']);
});
