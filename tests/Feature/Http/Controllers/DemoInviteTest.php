<?php

use App\Models\DemoEvent;
use App\Models\DemoParticipant;
use App\Models\DemoSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
    Config::set('demo.max_users_per_session', 3);
    Config::set('demo.shared_cache_store', 'array');
});

function invitedDemoSession(array $attributes = []): DemoSession
{
    return DemoSession::create(array_merge([
        'session_uuid' => (string) Str::uuid(),
        'role' => 'event_manager',
        'event_type' => 'FD',
        'invite_token' => Str::random(48),
        'visitor_hash' => str_repeat('a', 64),
        'user_agent' => 'Pest',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ], $attributes));
}

function demoPersona(string $roleName): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']));

    return $user;
}

test('a sandbox gets an invite token the first time it is shared and keeps it', function () {
    $demoSession = invitedDemoSession(['invite_token' => null]);

    $token = $demoSession->ensureInviteToken();

    expect($token)->toHaveLength(48)
        ->and($demoSession->fresh()->invite_token)->toBe($token)
        ->and($demoSession->ensureInviteToken())->toBe($token);
});

test('claiming seats stops at the configured limit', function () {
    $demoSession = invitedDemoSession();

    expect($demoSession->claimSeat())->toBeTrue()
        ->and($demoSession->claimSeat())->toBeTrue()
        ->and($demoSession->claimSeat())->toBeFalse()
        ->and($demoSession->participant_count)->toBe(3)
        ->and($demoSession->isFull())->toBeTrue();

});

test('rotating the invite token stops the old link working', function () {
    $demoSession = invitedDemoSession();
    $oldToken = $demoSession->invite_token;

    $newToken = $demoSession->rotateInviteToken();

    expect($newToken)->not->toBe($oldToken)->toHaveLength(48);

    $this->get(route('demo.join', $oldToken))->assertRedirect(route('demo.landing'));
    $this->get(route('demo.join', $newToken))->assertOk();
});

test('join page shows the shared sandbox and role choices', function () {
    $demoSession = invitedDemoSession(['event_type' => 'WFD']);

    $this->get(route('demo.join', $demoSession->invite_token))
        ->assertOk()
        ->assertSee('Winter Field Day')
        ->assertSee('1 of 3 spots taken')
        ->assertSee(route('demo.join.store', $demoSession->invite_token), false);
});

test('join page tells the visitor when the sandbox is full', function () {
    $demoSession = invitedDemoSession(['participant_count' => 3]);

    $this->get(route('demo.join', $demoSession->invite_token))
        ->assertOk()
        ->assertSee('This sandbox is full')
        ->assertDontSee(route('demo.join.store', $demoSession->invite_token), false);
});

test('join page sends visitors already in the sandbox straight to it', function () {
    $demoSession = invitedDemoSession();

    $this->withCookies(['demo_session' => $demoSession->session_uuid.'|1'])
        ->get(route('demo.join', $demoSession->invite_token))
        ->assertRedirect('/');
});

test('unknown or expired invite links send the visitor to the landing page', function (string $token) {
    $this->get(route('demo.join', $token))
        ->assertRedirect(route('demo.landing'))
        ->assertSessionHas('error');
})->with([
    'unknown token' => fn () => 'not-a-real-token',
    'expired sandbox' => fn () => invitedDemoSession(['expires_at' => now()->subMinute()])->invite_token,
]);

test('invite links stop working when invitations are turned off', function () {
    Config::set('demo.max_users_per_session', 1);
    $demoSession = invitedDemoSession();

    $this->get(route('demo.join', $demoSession->invite_token))
        ->assertRedirect(route('demo.landing'));
});

function joinDetails(array $overrides = []): array
{
    return array_merge([
        'role' => 'operator',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'call_sign' => 'k1ada',
    ], $overrides);
}

test('joining gives the visitor their own account with the chosen role', function () {
    $persona = demoPersona('Operator');
    $demoSession = invitedDemoSession();

    $response = $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails());

    $guest = User::where('call_sign', 'K1ADA')->sole();

    $response->assertRedirect('/');
    $response->assertCookie('demo_session', $demoSession->session_uuid.'|'.$guest->id);
    $this->assertAuthenticatedAs($guest);

    expect($guest->id)->not->toBe($persona->id)
        ->and($guest->first_name)->toBe('Ada')
        ->and($guest->last_name)->toBe('Lovelace')
        ->and($guest->hasRole('Operator'))->toBeTrue()
        ->and($demoSession->fresh()->participant_count)->toBe(2)
        ->and(DemoEvent::where('name', 'session.guest_joined')->count())->toBe(1);

    expect(DemoParticipant::sole())
        ->demo_session_id->toBe($demoSession->id)
        ->via_invite->toBeTrue()
        ->first_name->toBe('Ada')
        ->last_name->toBe('Lovelace')
        ->call_sign->toBe('K1ADA');
});

test('each guest who joins gets a separate account', function () {
    $demoSession = invitedDemoSession();
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);

    $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails(['call_sign' => null]));
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails(['first_name' => 'Grace', 'call_sign' => null]));

    $guests = User::role('Operator')->get();

    expect($guests)->toHaveCount(2)
        ->and($guests->pluck('call_sign')->unique())->toHaveCount(2)
        ->and($guests->pluck('call_sign')->every(fn (string $callSign): bool => preg_match('/^[AKNW][A-Z]?\d[A-Z]{1,3}$/', $callSign) === 1))->toBeTrue();
});

test('joining with a call sign another participant uses is refused without taking a seat', function () {
    demoPersona('Operator')->update(['call_sign' => 'K1ADA', 'email' => 'visitor-abc@demo.invalid']);
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails())
        ->assertSessionHasErrors('call_sign');

    expect($demoSession->fresh()->participant_count)->toBe(1);
    $this->assertGuest();
});

test('joining without a name or call sign gives the guest a generated identity', function () {
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'operator'])
        ->assertRedirect('/');

    $guest = User::sole();

    expect($guest->first_name)->toMatch('/^[A-Z][a-z]+$/')
        ->and($guest->last_name)->toMatch('/^[A-Z][a-z]+$/')
        ->and($guest->call_sign)->toMatch('/^[AKNW][A-Z]?\d[A-Z]{1,3}$/')
        ->and($guest->email)->toEndWith('@demo.invalid');

    expect(DemoParticipant::sole())
        ->first_name->toBeNull()
        ->last_name->toBeNull()
        ->call_sign->toBeNull();
});

test('joining with a last name but no first name is refused', function () {
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'operator', 'last_name' => 'Lovelace'])
        ->assertSessionHasErrors('first_name');
});

test('joining a full sandbox is refused without taking a seat', function () {
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $demoSession = invitedDemoSession(['participant_count' => 3]);

    $response = $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails());

    $response->assertRedirect(route('demo.join', $demoSession->invite_token));
    $response->assertSessionHas('error');
    $response->assertCookieMissing('demo_session');
    $this->assertGuest();

    expect($demoSession->fresh()->participant_count)->toBe(3)
        ->and(User::count())->toBe(0);
});

test('joining rejects an unknown role', function () {
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'supervillain'])
        ->assertSessionHasErrors('role');

    expect($demoSession->fresh()->participant_count)->toBe(1);
});

test('a guest starting over keeps their seat taken without marking the sandbox reset', function () {
    $demoSession = invitedDemoSession(['participant_count' => 2, 'host_user_id' => 7]);

    $this->withCookies(['demo_session' => $demoSession->session_uuid.'|42'])
        ->post(route('demo.reset'))
        ->assertRedirect(route('demo.landing'))
        ->assertCookieExpired('demo_session');

    $demoSession->refresh();

    expect($demoSession->participant_count)->toBe(2)
        ->and($demoSession->was_reset)->toBeFalse()
        ->and(DemoEvent::where('name', 'session.reset')->exists())->toBeFalse()
        ->and(DemoEvent::where('name', 'session.guest_left')->sole()->metadata)->toBe(['user_id' => 42]);
});

test('the host starting over marks the sandbox reset', function () {
    $demoSession = invitedDemoSession(['participant_count' => 2, 'host_user_id' => 7]);

    $this->withCookies(['demo_session' => $demoSession->session_uuid.'|7'])
        ->post(route('demo.reset'))
        ->assertRedirect(route('demo.landing'));

    expect($demoSession->fresh()->was_reset)->toBeTrue()
        ->and(DemoEvent::where('name', 'session.reset')->sole()->metadata)->toBe(['role' => 'event_manager'])
        ->and(DemoEvent::where('name', 'session.guest_left')->exists())->toBeFalse();
});

test('a guest starting over after the host does not clear the reset', function () {
    $demoSession = invitedDemoSession(['host_user_id' => 7, 'was_reset' => true]);

    $this->withCookies(['demo_session' => $demoSession->session_uuid.'|42'])
        ->post(route('demo.reset'));

    expect($demoSession->fresh()->was_reset)->toBeTrue();
});

test('starting over in a sandbox with no recorded host still marks it reset', function () {
    $demoSession = invitedDemoSession(['host_user_id' => null]);

    $this->withCookies(['demo_session' => $demoSession->session_uuid.'|42'])
        ->post(route('demo.reset'));

    expect($demoSession->fresh()->was_reset)->toBeTrue();
});

test('a guest\'s call sign wins over a seeded member who happens to hold it', function () {
    $seeded = demoPersona('Operator');
    $seeded->update(['call_sign' => 'K1ADA']);
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails())
        ->assertRedirect('/');

    expect(User::where('call_sign', 'K1ADA')->sole()->first_name)->toBe('Ada')
        ->and($seeded->fresh()->call_sign)->not->toBe('K1ADA')->toMatch('/^[AKNW][A-Z]?\d[A-Z]{1,3}$/');
});

test('a full sandbox does not rename a seeded member for a guest it turns away', function () {
    $seeded = demoPersona('Operator');
    $seeded->update(['call_sign' => 'K1ADA']);
    $demoSession = invitedDemoSession(['participant_count' => 3]);

    $this->post(route('demo.join.store', $demoSession->invite_token), joinDetails());

    expect($seeded->fresh()->call_sign)->toBe('K1ADA');
});

test('a guest still signed in from another sandbox is given the role they chose', function () {
    Config::set('developer.enabled', true);
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $demoSession = invitedDemoSession();

    // Every sandbox numbers its users alike, so the lingering login points at
    // the ID the guest's new account is about to take.
    $this->withSession([
        Auth::guard('web')->getName() => User::max('id') + 1,
        'dev_role_override' => 'Operator',
    ])->post(route('demo.join.store', $demoSession->invite_token), joinDetails());

    $guest = User::where('call_sign', 'K1ADA')->sole();

    expect($guest->roles()->pluck('name')->all())->toBe(['Operator']);
});
