<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Livewire\Users\UserManagement;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\AuthSecurity;
use App\Support\PasswordResetLinks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );

    foreach (['manage-users', 'manage-roles', 'manage-settings', 'log-contacts'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->adminRole = Role::findOrCreate('System Administrator', 'web');
    $this->adminRole->syncPermissions(['manage-users', 'manage-roles', 'manage-settings', 'log-contacts']);
    $this->operatorRole = Role::findOrCreate('Operator', 'web');
    $this->operatorRole->syncPermissions(['log-contacts']);

    $this->operator = User::factory()->create(['email' => 'op@example.com']);
    $this->operator->assignRole($this->operatorRole);

    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
    $this->admin->assignRole($this->adminRole);
});

/**
 * Fail a sign-in from a different address each time, so only the per-account limit applies.
 */
function failSignIn(string $email, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post('/login', ['email' => $email, 'password' => 'wrong-password']);
    }
}

function signIn(string $email, string $ip = '10.1.1.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/login', ['email' => $email, 'password' => 'password']);
}

// -----------------------------------------------------------------------------
// Failed sign-ins
// -----------------------------------------------------------------------------

test('progressive delay makes an account wait after the threshold, even from other machines', function () {
    config(['auth-security.failed_login_strategy' => 'progressive_delay', 'auth-security.lockout_threshold' => 5]);

    failSignIn('op@example.com', 5);

    signIn('op@example.com')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(31)->seconds();

    signIn('op@example.com')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->operator);
    expect($this->operator->fresh()->failed_login_attempts)->toBe(0);
});

test('progressive delay doubles with each further failure', function () {
    config(['auth-security.failed_login_strategy' => 'progressive_delay', 'auth-security.lockout_threshold' => 2]);

    failSignIn('op@example.com', 2);
    $this->travel(31)->seconds();
    failSignIn('op@example.com', 1);

    $this->travel(31)->seconds();
    signIn('op@example.com')->assertSessionHasErrors('email');

    $this->travel(30)->seconds();
    signIn('op@example.com')->assertSessionHasNoErrors();
});

test('fewer failures than the threshold cause no wait', function () {
    config(['auth-security.lockout_threshold' => 5]);

    failSignIn('op@example.com', 4);

    signIn('op@example.com')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->operator);
});

test('lockout blocks the account for LOCKOUT_DURATION minutes', function () {
    config([
        'auth-security.failed_login_strategy' => 'lockout',
        'auth-security.lockout_threshold' => 3,
        'auth-security.lockout_duration' => 15,
    ]);

    failSignIn('op@example.com', 3);

    $this->travel(14)->minutes();
    signIn('op@example.com')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(2)->minutes();
    signIn('op@example.com')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->operator);
});

// -----------------------------------------------------------------------------
// Password lengths
// -----------------------------------------------------------------------------

test('administrative accounts need PASSWORD_ADMIN_MIN_LENGTH', function () {
    config(['auth-security.password_min_length' => 8, 'auth-security.password_admin_min_length' => 12]);

    expect(fn () => app(ResetUserPassword::class)->reset($this->admin, [
        'password' => 'short-pw1',
        'password_confirmation' => 'short-pw1',
    ]))->toThrow(ValidationException::class);

    app(ResetUserPassword::class)->reset($this->operator, [
        'password' => 'short-pw1',
        'password_confirmation' => 'short-pw1',
    ]);

    expect(Hash::check('short-pw1', $this->operator->fresh()->password))->toBeTrue();
});

test('PASSWORD_MIN_LENGTH applies to everyone', function () {
    config(['auth-security.password_min_length' => 10]);

    expect(fn () => app(ResetUserPassword::class)->reset($this->operator, [
        'password' => 'nine-char',
        'password_confirmation' => 'nine-char',
    ]))->toThrow(ValidationException::class);
});

test('a new account with an administrative role needs the admin length', function () {
    config(['auth-security.password_admin_min_length' => 14]);

    Livewire::actingAs($this->admin)->test(UserManagement::class)
        ->call('openCreateModal')
        ->set('inviteMode', false)
        ->set('call_sign', 'W1NEW')
        ->set('first_name', 'New')
        ->set('last_name', 'Admin')
        ->set('email', 'new@example.com')
        ->set('role_id', $this->adminRole->id)
        ->set('password', 'twelve-chars')
        ->set('password_confirmation', 'twelve-chars')
        ->call('saveUser')
        ->assertHasErrors('password');
});

// -----------------------------------------------------------------------------
// Idle timeouts
// -----------------------------------------------------------------------------

test('accounts are signed out after their idle timeout', function (string $who, int $idleMinutes, bool $signedOut) {
    config(['auth-security.session_timeout_admin' => 30, 'auth-security.session_timeout_default' => 240, 'auth-security.event_duration_mode' => true]);

    $this->actingAs($this->{$who})->get('/profile')->assertOk();
    $this->travel($idleMinutes)->minutes();

    $response = $this->get('/profile');

    if ($signedOut) {
        $response->assertRedirect(route('login', ['account' => EnforceIdleTimeout::IDLE_REASON]));
        $this->assertGuest();
    } else {
        $response->assertOk();
    }
})->with([
    'admin within 30 minutes' => ['admin', 29, false],
    'admin after 30 minutes' => ['admin', 31, true],
    'operator after 30 minutes' => ['operator', 31, false],
    'operator after 240 minutes' => ['operator', 241, true],
]);

test('nobody is signed out for idling while an event is in progress', function () {
    config(['auth-security.session_timeout_admin' => 30, 'auth-security.event_duration_mode' => true]);
    Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);

    $this->actingAs($this->admin)->get('/profile')->assertOk();
    $this->travel(5)->hours();

    $this->get('/profile')->assertOk();
});

test('with EVENT_DURATION_MODE off, idle sign-outs apply during an event', function () {
    config(['auth-security.session_timeout_admin' => 30, 'auth-security.event_duration_mode' => false]);
    Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);

    $this->actingAs($this->admin)->get('/profile')->assertOk();
    $this->travel(31)->minutes();

    $this->get('/profile')->assertRedirect();
    $this->assertGuest();
});

test('Livewire requests from an idle session get a 419 pointing at the login page', function () {
    config(['auth-security.session_timeout_default' => 240]);

    $this->actingAs($this->operator)->get('/profile')->assertOk();
    $this->travel(241)->minutes();

    $this->withHeader('X-Livewire', 'true')->get('/profile')
        ->assertStatus(419)
        ->assertJsonPath('redirect', route('login', ['account' => EnforceIdleTimeout::IDLE_REASON]));
});

test('the login page explains an idle sign-out', function () {
    $this->get(route('login', ['account' => EnforceIdleTimeout::IDLE_REASON]))
        ->assertSee('signed out after a period of inactivity');
});

test('the session outlasts the idle timeouts and, in event mode, an event', function () {
    config(['session.lifetime' => 120, 'auth-security.session_timeout_default' => 240, 'auth-security.event_duration_mode' => false]);
    expect(AuthSecurity::sessionLifetimeMinutes())->toBe(240);

    config(['auth-security.event_duration_mode' => true]);
    expect(AuthSecurity::sessionLifetimeMinutes())->toBe(2880);
});

// -----------------------------------------------------------------------------
// Password reset method
// -----------------------------------------------------------------------------

test('admin_only turns off forgot-password emails', function () {
    config(['app.url' => 'https://fd.example.org', 'auth-security.password_reset_method' => 'admin_only']);
    Route::middleware('web')->post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'op@example.com'])
        ->assertSessionHasErrors(['email' => PasswordResetLinks::ADMIN_ONLY_MESSAGE]);

    Notification::assertNothingSent();
});

test('email-only reset stops administrators setting a temporary password', function () {
    config(['auth-security.password_reset_method' => 'email', 'mail.email_configured' => true]);
    $original = $this->operator->password;

    Livewire::actingAs($this->admin)->test(UserManagement::class)
        ->call('openResetModal', $this->operator->id)
        ->assertSet('resetMethod', 'email')
        ->set('resetMethod', 'manual')
        ->call('resetPassword')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'reset by email only'));

    expect($this->operator->fresh()->password)->toBe($original);
});

test('email-only reset still allows temporary passwords when email is not configured', function () {
    config(['auth-security.password_reset_method' => 'email', 'mail.email_configured' => false]);

    Livewire::actingAs($this->admin)->test(UserManagement::class)
        ->call('openResetModal', $this->operator->id)
        ->assertSet('resetMethod', 'manual')
        ->call('resetPassword')
        ->assertDispatched('toast', fn ($name, $params) => $params['description'] === 'Password reset.');
});

test('temporary passwords meet the admin length when it is over 16', function () {
    config(['auth-security.password_admin_min_length' => 20]);

    $component = Livewire::actingAs($this->admin)->test(UserManagement::class)
        ->call('openResetModal', $this->admin->id);

    expect(strlen($component->get('newPassword')))->toBe(20);
});

test('an invalid PASSWORD_RESET_METHOD stops the app booting', function () {
    config(['auth-security.password_reset_method' => 'sometimes']);

    expect(fn () => (new FortifyServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'PASSWORD_RESET_METHOD');
});

// -----------------------------------------------------------------------------
// Audit retention
// -----------------------------------------------------------------------------

test('audit:cleanup keeps AUDIT_RETENTION_DAYS of non-critical entries', function () {
    config(['auth-security.audit_retention_days' => 30]);

    $old = AuditLog::forceCreate(['action' => 'user.login', 'ip_address' => '127.0.0.1', 'created_at' => now()->subDays(31), 'is_critical' => false]);
    $recent = AuditLog::forceCreate(['action' => 'user.login', 'ip_address' => '127.0.0.1', 'created_at' => now()->subDays(29), 'is_critical' => false]);
    $critical = AuditLog::forceCreate(['action' => 'user.login', 'ip_address' => '127.0.0.1', 'created_at' => now()->subDays(400), 'is_critical' => true]);

    $this->artisan('audit:cleanup')->assertSuccessful();

    expect(AuditLog::find($old->id))->toBeNull()
        ->and(AuditLog::find($recent->id))->not->toBeNull()
        ->and(AuditLog::find($critical->id))->not->toBeNull();
});

test('audit:cleanup runs daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'audit:cleanup'));

    expect($events)->toHaveCount(1);
});
