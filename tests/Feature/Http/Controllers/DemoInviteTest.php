<?php

use App\Http\Controllers\DemoController;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $demoSession->releaseSeat();

    expect($demoSession->participant_count)->toBe(2)
        ->and($demoSession->claimSeat())->toBeTrue();
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

    $this->withUnencryptedCookies(['demo_session' => $demoSession->session_uuid.'|operator'])
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

test('joining takes a seat and signs the visitor in as the chosen role', function () {
    $operator = demoPersona('Operator');
    $demoSession = invitedDemoSession();

    $response = $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'operator']);

    $response->assertRedirect('/');
    $response->assertCookie('demo_session', $demoSession->session_uuid.'|operator', encrypted: false);
    $response->assertCookie(DemoController::GUEST_COOKIE, $demoSession->session_uuid);
    $this->assertAuthenticatedAs($operator);

    expect($demoSession->fresh()->participant_count)->toBe(2)
        ->and(DemoEvent::where('name', 'session.guest_joined')->count())->toBe(1);
});

test('joining a full sandbox is refused without taking a seat', function () {
    demoPersona('Operator');
    $demoSession = invitedDemoSession(['participant_count' => 3]);

    $response = $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'operator']);

    $response->assertRedirect(route('demo.join', $demoSession->invite_token));
    $response->assertSessionHas('error');
    $response->assertCookieMissing('demo_session');
    $this->assertGuest();

    expect($demoSession->fresh()->participant_count)->toBe(3);
});

test('joining rejects an unknown role', function () {
    $demoSession = invitedDemoSession();

    $this->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'supervillain'])
        ->assertSessionHasErrors('role');

    expect($demoSession->fresh()->participant_count)->toBe(1);
});

test('joining from another sandbox gives up the seat there', function () {
    demoPersona('Operator');
    $previous = invitedDemoSession(['participant_count' => 2]);
    $demoSession = invitedDemoSession();

    $this->withUnencryptedCookies(['demo_session' => $previous->session_uuid.'|operator'])
        ->post(route('demo.join.store', $demoSession->invite_token), ['role' => 'operator'])
        ->assertRedirect('/');

    expect($previous->fresh()->participant_count)->toBe(1)
        ->and($demoSession->fresh()->participant_count)->toBe(2);
});

test('a guest leaving frees their seat without marking the sandbox reset', function () {
    $demoSession = invitedDemoSession(['participant_count' => 2]);

    $this->withUnencryptedCookies(['demo_session' => $demoSession->session_uuid.'|operator'])
        ->withCookie(DemoController::GUEST_COOKIE, $demoSession->session_uuid)
        ->post(route('demo.reset'))
        ->assertRedirect(route('demo.landing'))
        ->assertCookieExpired(DemoController::GUEST_COOKIE);

    $demoSession->refresh();

    expect($demoSession->participant_count)->toBe(1)
        ->and($demoSession->was_reset)->toBeFalse()
        ->and(DemoEvent::where('name', 'session.guest_left')->count())->toBe(1);
});

test('the host starting over frees their seat and marks the sandbox reset', function () {
    $demoSession = invitedDemoSession(['participant_count' => 2]);

    $this->withUnencryptedCookies(['demo_session' => $demoSession->session_uuid.'|event_manager'])
        ->post(route('demo.reset'))
        ->assertRedirect(route('demo.landing'));

    $demoSession->refresh();

    expect($demoSession->participant_count)->toBe(1)
        ->and($demoSession->was_reset)->toBeTrue();
});
