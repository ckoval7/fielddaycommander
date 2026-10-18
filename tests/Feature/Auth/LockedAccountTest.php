<?php

use App\Http\Middleware\EnsureAccountNotLocked;
use App\Models\StationDevice;
use App\Models\User;
use App\Models\UserInvitation;
use App\Providers\FortifyServiceProvider;
use App\Support\StationMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('a newly registered user awaiting approval is told the request is pending', function () {
    $pending = User::factory()->create(['account_locked_at' => now(), 'approval_requested_at' => now(), 'last_login_at' => null]);

    $this->post('/login', ['email' => $pending->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => FortifyServiceProvider::PENDING_APPROVAL_MESSAGE]);

    $this->assertGuest();
});

test('a locked user with the correct password is told the account is locked', function () {
    $locked = User::factory()->create(['account_locked_at' => now(), 'last_login_at' => now()->subDay()]);

    $this->post('/login', ['email' => $locked->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE]);

    $this->assertGuest();
});

test('an admin-locked user who has never signed in is told the account is locked', function () {
    $locked = User::factory()->create(['account_locked_at' => now(), 'approval_requested_at' => null, 'last_login_at' => null]);

    $this->post('/login', ['email' => $locked->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE]);

    $this->assertGuest();
});

test('a locked user with a wrong password gets the generic error', function () {
    $locked = User::factory()->create(['account_locked_at' => now()]);

    $this->post('/login', ['email' => $locked->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('an existing session is ended once the account is locked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard.alt'))->assertOk();

    $user->update(['account_locked_at' => now()]);

    $this->get(route('dashboard.alt'))
        ->assertRedirect(route('login', ['account' => 'locked']));

    $this->assertGuest();

    $this->get(route('login', ['account' => 'locked']))
        ->assertOk()
        ->assertSee(EnsureAccountNotLocked::LOCKED_MESSAGE);
});

test('the login page ignores an unknown account reason', function () {
    $this->get(route('login', ['account' => 'bogus']))
        ->assertOk()
        ->assertDontSee(FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE)
        ->assertDontSee(FortifyServiceProvider::PENDING_APPROVAL_MESSAGE);

    $this->get('/login?account[]=locked')->assertOk();
});

test('a locked user on a Livewire request gets a 419 and is signed out', function () {
    $user = User::factory()->create(['account_locked_at' => now()]);

    $this->actingAs($user)
        ->withHeaders(['X-Livewire' => 'true'])
        ->get(route('dashboard.alt'))
        ->assertStatus(419)
        ->assertJson([
            'message' => FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE,
            'redirect' => route('login', ['account' => 'locked']),
        ]);

    $this->assertGuest();
});

test('a pending user on a Livewire request is sent to the login page with the pending reason', function () {
    $user = User::factory()->create(['account_locked_at' => now(), 'approval_requested_at' => now()]);

    $this->actingAs($user)
        ->withHeaders(['X-Livewire' => 'true'])
        ->get(route('dashboard.alt'))
        ->assertStatus(419)
        ->assertJson(['redirect' => route('login', ['account' => 'pending'])]);

    $this->assertGuest();

    $this->get(route('login', ['account' => 'pending']))
        ->assertOk()
        ->assertSee(FortifyServiceProvider::PENDING_APPROVAL_MESSAGE);
});

test('unlocked users keep their session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard.alt'))->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('ghost users signed in through station mode are not signed out', function () {
    $ghost = User::factory()->create(['is_ghost' => true, 'account_locked_at' => now()]);
    $device = StationDevice::factory()->withToken('device-token')->create();

    $this->actingAs($ghost)
        ->withCookie(StationMode::COOKIE, 'device-token')
        ->withSession([StationMode::SESSION_KEY => $device->id])
        ->get(route('weather.index'))
        ->assertOk();

    $this->assertAuthenticatedAs($ghost);
});

test('ghost users outside station mode are signed out', function () {
    $ghost = User::factory()->create(['is_ghost' => true, 'account_locked_at' => now()]);

    $this->actingAs($ghost)
        ->get(route('weather.index'))
        ->assertRedirect(route('login', ['account' => 'locked']));

    $this->assertGuest();
});

test('a locked user cannot accept an invitation', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'account_locked_at' => now(),
    ]);
    $originalHash = $user->password;

    $token = Str::random(64);
    UserInvitation::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addHours(72),
    ]);

    $this->post("/register/invite/{$token}", [
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()->password)->toBe($originalHash)
        ->and(UserInvitation::where('token', $token)->first()->accepted_at)->toBeNull();
});
