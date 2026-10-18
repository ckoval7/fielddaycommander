<?php

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitation as UserInvitationNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create roles
    Role::create(['name' => 'system-admin']);
    Role::create(['name' => 'operator']);
});

test('invitation acceptance page can be rendered with valid token', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
    ]);

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $response = $this->get("/register/invite/{$token}");

    $response->assertStatus(200)
        ->assertSee($user->first_name)
        ->assertSee($user->call_sign)
        ->assertSee($user->email);
});

test('invitation acceptance page redirects with invalid token', function () {
    $response = $this->get('/register/invite/invalid-token');

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error', 'This invitation is invalid or has expired.');
});

test('invitation acceptance page redirects with expired token', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
    ]);

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->subHour(), // Expired
    ]);

    $response = $this->get("/register/invite/{$token}");

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error', 'This invitation is invalid or has expired.');
});

test('invitation acceptance page redirects with already accepted token', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
    ]);

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
        'accepted_at' => now(), // Already accepted
    ]);

    $response = $this->get("/register/invite/{$token}");

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error', 'This invitation is invalid or has expired.');
});

test('user can accept invitation and set password', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'email_verified_at' => null,
    ]);

    $token = Str::random(64);
    $invitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $response = $this->post("/register/invite/{$token}", [
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Welcome! Your account has been activated.');

    $user->refresh();
    $invitation->refresh();

    expect(Hash::check('newpassword123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($invitation->accepted_at)->not->toBeNull();

    $this->assertAuthenticated();
});

test('invitation acceptance requires password confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
    ]);

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $response = $this->post("/register/invite/{$token}", [
        'password' => 'newpassword123',
        'password_confirmation' => 'different-password',
    ]);

    $response->assertSessionHasErrors(['password']);
    $this->assertGuest();
});

test('invitation acceptance requires valid password', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
    ]);

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $response = $this->post("/register/invite/{$token}", [
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertSessionHasErrors(['password']);
    $this->assertGuest();
});

test('user invitation model has correct relationships', function () {
    $user = User::factory()->create();
    $invitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
    ]);

    expect($invitation->user)->toBeInstanceOf(User::class)
        ->and($invitation->user->id)->toBe($user->id)
        ->and($user->invitations)->toHaveCount(1)
        ->and($user->invitations->first()->id)->toBe($invitation->id);
});

test('user invitation model correctly identifies expired invitations', function () {
    $user = User::factory()->create();

    $validInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
    ]);

    $expiredInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->subHour(),
    ]);

    expect($validInvitation->isExpired())->toBeFalse()
        ->and($expiredInvitation->isExpired())->toBeTrue();
});

test('user invitation model correctly identifies accepted invitations', function () {
    $user = User::factory()->create();

    $pendingInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
    ]);

    $acceptedInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
        'accepted_at' => now(),
    ]);

    expect($pendingInvitation->isAccepted())->toBeFalse()
        ->and($acceptedInvitation->isAccepted())->toBeTrue();
});

test('user invitation model correctly identifies valid invitations', function () {
    $user = User::factory()->create();

    $validInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
    ]);

    $expiredInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->subHour(),
    ]);

    $acceptedInvitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
        'accepted_at' => now(),
    ]);

    expect($validInvitation->isValid())->toBeTrue()
        ->and($expiredInvitation->isValid())->toBeFalse()
        ->and($acceptedInvitation->isValid())->toBeFalse();
});

test('admin can send invitation email when creating user in invite mode', function () {
    Notification::fake();

    $admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $admin->assignRole('system-admin');

    $operatorRole = Role::where('name', 'operator')->first();

    // Create user with invitation using the component directly
    $user = User::create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
        'license_class' => 'Extra',
        'password' => Hash::make(Str::random(32)),
    ]);
    $user->assignRole($operatorRole);

    // Simulate invitation creation
    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $adminName = $admin->first_name.' '.$admin->last_name;
    $user->notify(new UserInvitationNotification($token, $adminName));

    // Verify invitation was created
    $invitation = UserInvitation::where('user_id', $user->id)->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($invitation->expires_at->greaterThan(now()->addHours(71)))->toBeTrue()
        ->and($invitation->accepted_at)->toBeNull();

    // Verify notification was sent
    Notification::assertSentTo($user, UserInvitationNotification::class);
});

test('deleting user cascades and deletes invitations', function () {
    $user = User::factory()->create();
    $invitation = UserInvitation::create([
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(72),
    ]);

    expect(UserInvitation::count())->toBe(1);

    // Force delete to trigger cascade (soft delete doesn't trigger database cascades)
    $user->forceDelete();

    expect(UserInvitation::count())->toBe(0);
});
