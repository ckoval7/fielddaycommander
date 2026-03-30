<?php

use App\Livewire\Profile\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create roles for testing
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    // Create and authenticate a user
    $this->user = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password123'),
        'license_class' => 'Extra',
        'preferred_timezone' => 'America/New_York',
        'notification_preferences' => [
            'event_notifications' => true,
            'system_announcements' => true,
        ],
    ]);
    $this->user->assignRole('Operator');

    $this->actingAs($this->user);
});

// =============================================================================
// Profile Tab Tests (7 tests)
// =============================================================================

test('component loads with profile data', function () {
    Livewire::test(UserProfile::class)
        ->assertSet('call_sign', 'W1AW')
        ->assertSet('first_name', 'John')
        ->assertSet('last_name', 'Doe')
        ->assertSet('email', 'john@example.com')
        ->assertSet('license_class', 'Extra')
        ->assertSet('preferred_timezone', 'America/New_York')
        ->assertSet('event_notifications', true)
        ->assertSet('system_announcements', true);
});

test('profile tab is active by default', function () {
    Livewire::test(UserProfile::class)
        ->assertSet('activeTab', 'profile');
});

test('can update profile information', function () {
    Livewire::test(UserProfile::class)
        ->set('first_name', 'Jane')
        ->set('last_name', 'Smith')
        ->set('email', 'jane@example.com')
        ->set('license_class', 'General')
        ->set('preferred_timezone', 'America/Los_Angeles')
        ->call('saveProfile')
        ->assertDispatched('toast');

    $this->user->refresh();
    expect($this->user->first_name)->toBe('Jane')
        ->and($this->user->last_name)->toBe('Smith')
        ->and($this->user->email)->toBe('jane@example.com')
        ->and($this->user->license_class)->toBe('General')
        ->and($this->user->preferred_timezone)->toBe('America/Los_Angeles');
});

test('can update notification preferences', function () {
    Livewire::test(UserProfile::class)
        ->set('event_notifications', false)
        ->set('system_announcements', false)
        ->call('saveProfile')
        ->assertDispatched('toast');

    $this->user->refresh();
    expect($this->user->notification_preferences['event_notifications'])->toBeFalse()
        ->and($this->user->notification_preferences['system_announcements'])->toBeFalse();
});

test('profile update validates required fields', function () {
    Livewire::test(UserProfile::class)
        ->set('first_name', '')
        ->set('last_name', '')
        ->set('email', '')
        ->call('saveProfile')
        ->assertHasErrors(['first_name', 'last_name', 'email']);
});

test('profile update validates email format', function () {
    Livewire::test(UserProfile::class)
        ->set('email', 'invalid-email')
        ->call('saveProfile')
        ->assertHasErrors(['email']);
});

test('profile update validates license class', function () {
    Livewire::test(UserProfile::class)
        ->set('license_class', 'Invalid Class')
        ->call('saveProfile')
        ->assertHasErrors(['license_class']);

    Livewire::test(UserProfile::class)
        ->set('license_class', 'Technician')
        ->call('saveProfile')
        ->assertHasNoErrors(['license_class']);
});

// =============================================================================
// Security Tab Tests (5 tests)
// =============================================================================

test('can change password with valid credentials', function () {
    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->set('password', 'newpassword456')
        ->set('password_confirmation', 'newpassword456')
        ->call('changePassword')
        ->assertDispatched('toast');

    $this->user->refresh();
    expect(Hash::check('newpassword456', $this->user->password))->toBeTrue();
});

test('password change clears requires_password_change flag', function () {
    $this->user->update(['requires_password_change' => true]);

    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->set('password', 'newpassword456')
        ->set('password_confirmation', 'newpassword456')
        ->call('changePassword');

    $this->user->refresh();
    expect($this->user->requires_password_change)->toBeFalse();
});

test('password change validates current password', function () {
    Livewire::test(UserProfile::class)
        ->set('current_password', 'wrongpassword')
        ->set('password', 'newpassword456')
        ->set('password_confirmation', 'newpassword456')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);

    $this->user->refresh();
    expect(Hash::check('password123', $this->user->password))->toBeTrue();
});

test('password change validates password confirmation', function () {
    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->set('password', 'newpassword456')
        ->set('password_confirmation', 'differentpassword')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

test('password change validates minimum length', function () {
    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

test('password fields are cleared after successful change', function () {
    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->set('password', 'newpassword456')
        ->set('password_confirmation', 'newpassword456')
        ->call('changePassword')
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');
});

// =============================================================================
// Sessions Tab Tests (3 tests)
// =============================================================================

test('displays active login sessions', function () {
    // Create a session for the user
    DB::table('sessions')->insert([
        'id' => 'session-123',
        'user_id' => $this->user->id,
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'test-payload',
        'last_activity' => now()->timestamp,
    ]);

    $component = Livewire::test(UserProfile::class);
    $sessions = $component->viewData('sessions');

    expect($sessions)->toHaveCount(1)
        ->and($sessions->first()->ip_address)->toBe('192.168.1.1');
});

test('sessions are ordered by last activity', function () {
    DB::table('sessions')->insert([
        [
            'id' => 'session-1',
            'user_id' => $this->user->id,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'test-payload',
            'last_activity' => now()->subHours(2)->timestamp,
        ],
        [
            'id' => 'session-2',
            'user_id' => $this->user->id,
            'ip_address' => '192.168.1.2',
            'user_agent' => 'Chrome',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ],
    ]);

    $component = Livewire::test(UserProfile::class);
    $sessions = $component->viewData('sessions');

    expect($sessions->first()->ip_address)->toBe('192.168.1.2')
        ->and($sessions->last()->ip_address)->toBe('192.168.1.1');
});

test('can logout from other sessions', function () {
    // Create multiple sessions
    DB::table('sessions')->insert([
        [
            'id' => 'session-1',
            'user_id' => $this->user->id,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'session-2',
            'user_id' => $this->user->id,
            'ip_address' => '192.168.1.2',
            'user_agent' => 'Chrome',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ],
    ]);

    Livewire::test(UserProfile::class)
        ->set('current_password', 'password123')
        ->call('logoutOtherSessions')
        ->assertDispatched('toast')
        ->assertSet('current_password', '');
});

// =============================================================================
// Operating History Tab Tests (2 tests)
// =============================================================================

test('displays operating sessions for user', function () {
    $component = Livewire::test(UserProfile::class);
    $operatingSessions = $component->viewData('operatingSessions');

    expect($operatingSessions)->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

test('operating sessions are empty placeholder when no data', function () {
    $component = Livewire::test(UserProfile::class);
    $operatingSessions = $component->viewData('operatingSessions');

    expect($operatingSessions)->toBeEmpty();
});

// =============================================================================
// Activity Tab Tests (2 tests)
// =============================================================================

test('displays activity log for user', function () {
    $component = Livewire::test(UserProfile::class);
    $activityLog = $component->viewData('activityLog');

    expect($activityLog)->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

test('activity log is empty placeholder when no data', function () {
    $component = Livewire::test(UserProfile::class);
    $activityLog = $component->viewData('activityLog');

    expect($activityLog)->toBeEmpty();
});
