<?php

use App\Http\Controllers\DemoController;
use App\Models\DemoPoolDatabase;
use App\Models\DemoSession;
use App\Models\User;
use App\Services\DemoDatabasePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.max_sessions', 25);
    Config::set('demo.ttl_hours', 24);
});

test('landing page renders when demo mode is enabled', function () {
    $response = $this->get(route('demo.landing'));
    $response->assertStatus(200);
    $response->assertSee('Operator');
    $response->assertSee('Event Manager');
});

test('landing page offers both events, defaulting to Field Day', function () {
    $response = $this->get(route('demo.landing'));

    $response->assertStatus(200);
    $response->assertSee('ARRL Field Day');
    $response->assertSee('Winter Field Day');
    $response->assertSee('name="event_type" value="FD"', false);
});

test('landing page returns 404 when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('demo.landing'));
    $response->assertStatus(404);
});

test('provision rejects invalid role', function () {
    $response = $this->post(route('demo.provision'), [
        'role' => 'supervillain',
        'event_type' => 'FD',
    ]);
    $response->assertSessionHasErrors('role');
});

test('provision rejects an unsupported event type', function () {
    $response = $this->post(route('demo.provision'), [
        'role' => 'operator',
        'event_type' => 'SPRINT',
    ]);
    $response->assertSessionHasErrors('event_type');
});

test('provision requires an event type', function () {
    $response = $this->post(route('demo.provision'), ['role' => 'operator']);
    $response->assertSessionHasErrors('event_type');
});

test('provision redirects back with error when session cap is reached', function () {
    Config::set('demo.max_sessions', 0);
    $response = $this->post(route('demo.provision'), [
        'role' => 'operator',
        'event_type' => 'WFD',
    ]);
    $response->assertRedirect(route('demo.landing'));
    $response->assertSessionHas('error');
});

test('reset redirects to role picker and clears cookie', function () {
    $uuid = fake()->uuid();

    $response = $this->withCookies(['demo_session' => $uuid.'|1'])
        ->post(route('demo.reset'));

    $response->assertRedirect(route('demo.landing'));
    $response->assertCookieExpired('demo_session');
});

test('reset without valid cookie redirects to role picker', function () {
    $response = $this->post(route('demo.reset'));
    $response->assertRedirect(route('demo.landing'));
});

test('session cap excludes demo_base and malformed schema names', function () {
    Config::set('demo.max_sessions', 1);

    DB::shouldReceive('getDriverName')->andReturn('mysql');
    DB::shouldReceive('select')->once()->andReturn([
        (object) ['schema_name' => 'demo_base'],
        (object) ['schema_name' => 'demo_garbage'],
        (object) ['schema_name' => 'demo_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ]);

    $reflection = new ReflectionMethod(DemoController::class, 'countDemoSessions');
    $reflection->setAccessible(true);
    $count = $reflection->invoke(new DemoController, new DemoDatabasePool);

    expect($count)->toBe(1);
});

test('session cap leaves out pooled databases nobody has claimed', function () {
    DemoPoolDatabase::create(['session_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);

    DB::shouldReceive('getDriverName')->andReturn('mysql');
    DB::shouldReceive('select')->once()->andReturn([
        (object) ['schema_name' => 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa'],
        (object) ['schema_name' => 'demo_bbbbbbbb_bbbb_bbbb_bbbb_bbbbbbbbbbbb'],
    ]);

    $reflection = new ReflectionMethod(DemoController::class, 'countDemoSessions');
    $count = $reflection->invoke(new DemoController, new DemoDatabasePool);

    expect($count)->toBe(1);
});

test('provision sends the visitor back to retry when every provisioning slot is busy', function () {
    Config::set('demo.shared_cache_store', 'array');
    Config::set('demo.provision_concurrency', 1);
    Config::set('demo.provision_wait_seconds', 0);
    Cache::store('array')->lock('demo:provision-slot:1', 60)->get();

    $response = $this->post(route('demo.provision'), [
        'role' => 'station_captain',
        'event_type' => 'WFD',
        'attempt' => 2,
        'first_name' => 'Ada',
        'call_sign' => 'K1ADA',
    ]);

    $response->assertRedirect(route('demo.landing'));
    $response->assertSessionHas('provision_retry', [
        'role' => 'station_captain',
        'event_type' => 'WFD',
        'attempt' => 3,
        'first_name' => 'Ada',
        'last_name' => null,
        'call_sign' => 'K1ADA',
    ]);
    expect(DemoPoolDatabase::count())->toBe(0);
});

test('provision gives up retrying after the final attempt', function () {
    Config::set('demo.shared_cache_store', 'array');
    Config::set('demo.provision_concurrency', 1);
    Config::set('demo.provision_wait_seconds', 0);
    Cache::store('array')->lock('demo:provision-slot:1', 60)->get();

    $response = $this->post(route('demo.provision'), [
        'role' => 'operator',
        'event_type' => 'FD',
        'attempt' => 10,
    ]);

    $response->assertRedirect(route('demo.landing'));
    $response->assertSessionMissing('provision_retry');
    $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'very busy'));
});

test('landing page resubmits a visitor who is waiting for a provisioning slot', function () {
    $response = $this->withSession(['provision_retry' => [
        'role' => 'event_manager',
        'event_type' => 'WFD',
        'attempt' => 4,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'call_sign' => 'K1ADA',
    ]])->get(route('demo.landing'));

    $response->assertOk();
    $response->assertSee('id="provision-retry"', false);
    $response->assertSee('name="role" value="event_manager"', false);
    $response->assertSee('name="event_type" value="WFD"', false);
    $response->assertSee('name="attempt" value="4"', false);
    $response->assertSee('name="first_name" value="Ada"', false);
    $response->assertSee('name="last_name" value="Lovelace"', false);
    $response->assertSee('name="call_sign" value="K1ADA"', false);
});

test('landing page does not resubmit without a pending retry', function () {
    $this->get(route('demo.landing'))
        ->assertOk()
        ->assertDontSee('id="provision-retry"', false);
});

test('provision rejects a malformed call sign before building a sandbox', function () {
    $this->post(route('demo.provision'), [
        'role' => 'operator',
        'event_type' => 'FD',
        'call_sign' => 'not a call',
    ])->assertSessionHasErrors('call_sign');

    expect(DemoPoolDatabase::count())->toBe(0);
});

test('landing page asks for an optional name and call sign', function () {
    $this->get(route('demo.landing'))
        ->assertOk()
        ->assertSee('name="first_name"', false)
        ->assertSee('name="call_sign"', false)
        ->assertSee('name="role" value="system_admin"', false);
});

function applyDemoIdentity(User $user, array $validated): void
{
    (new ReflectionMethod(DemoController::class, 'applyIdentity'))
        ->invoke(new DemoController, $user, $validated);
}

test('the visitor who starts a sandbox takes over the persona under their own name', function () {
    $persona = User::factory()->create(['first_name' => 'Carol', 'last_name' => 'Jones', 'call_sign' => 'N4DEF']);

    applyDemoIdentity($persona, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'call_sign' => 'k1ada']);

    expect($persona->fresh())
        ->first_name->toBe('Ada')
        ->last_name->toBe('Lovelace')
        ->call_sign->toBe('K1ADA');
});

test('a visitor who leaves their identity blank keeps the persona\'s generated one', function () {
    $persona = User::factory()->create(['first_name' => 'Carol', 'last_name' => 'Jones', 'call_sign' => 'N4DEF']);

    applyDemoIdentity($persona, ['first_name' => null, 'last_name' => null, 'call_sign' => null]);

    expect($persona->fresh())
        ->first_name->toBe('Carol')
        ->call_sign->toBe('N4DEF');
});

test('a visitor starting a sandbox keeps their call sign when a seeded member holds it', function () {
    $seeded = User::factory()->create(['call_sign' => 'W1ABC']);
    $persona = User::factory()->create(['call_sign' => 'N4DEF']);

    applyDemoIdentity($persona, ['call_sign' => 'w1abc']);

    expect($persona->fresh()->call_sign)->toBe('W1ABC')
        ->and($seeded->fresh()->call_sign)->not->toBe('W1ABC')->toMatch('/^[AKNW][A-Z]?\d[A-Z]{1,3}$/');
});

test('the persona a visitor takes over is marked as theirs', function () {
    $persona = User::factory()->create(['email' => 'admin@demo.example']);

    applyDemoIdentity($persona, []);

    expect($persona->fresh()->email)->toEndWith('@demo.invalid');
});

test('a visitor cannot take the reserved system call sign', function () {
    applyDemoIdentity(User::factory()->create(), ['call_sign' => 'system']);
})->throws(ValidationException::class);

test('the identity fields remember what the visitor typed in their browser', function (string $page) {
    $url = $page === 'landing'
        ? route('demo.landing')
        : route('demo.join', DemoSession::create([
            'session_uuid' => (string) Str::uuid(),
            'role' => 'operator',
            'event_type' => 'FD',
            'invite_token' => Str::random(48),
            'visitor_hash' => str_repeat('a', 64),
            'user_agent' => 'Pest',
            'provisioned_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(24),
        ])->invite_token);

    $this->get($url)
        ->assertOk()
        ->assertSee('data-demo-identity', false)
        ->assertSee('localStorage.setItem(storageKey', false);
})->with(['landing', 'join']);

test('provision signs out whoever the browser was last signed in as', function () {
    Config::set('demo.max_sessions', 0);

    $this->actingAs(User::factory()->create())
        ->withSession(['dev_role_override' => 'Event Manager'])
        ->post(route('demo.provision'), ['role' => 'event_manager', 'event_type' => 'FD'])
        ->assertSessionMissing('dev_role_override');

    $this->assertGuest();
});
