<?php

use App\Http\Controllers\DemoController;
use App\Http\Middleware\DemoMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
    Config::set('demo.max_sessions', 25);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now(), 'created_at' => now()]
    );
});

test('redirects to demo landing when no session cookie is present', function () {
    $response = $this->get('/');
    $response->assertRedirect(route('demo.landing'));
});

test('redirects to demo landing when cookie contains non-UUID value', function () {
    $response = $this->withUnencryptedCookies(['demo_session' => 'not-a-uuid'])->get('/');
    $response->assertRedirect(route('demo.landing'));
});

test('redirects to demo landing when demo database does not exist', function () {
    $uuid = (string) Str::uuid();
    $response = $this->withUnencryptedCookies(['demo_session' => $uuid])->get('/');
    $response->assertRedirect(route('demo.landing'));
});

test('passes through demo landing route without a cookie', function () {
    $response = $this->get(route('demo.landing'));
    $response->assertStatus(200);
});

test('redirects login page to demo landing without demo session', function () {
    $response = $this->get('/login');
    $response->assertRedirect(route('demo.landing'));
});

test('redirects admin routes to demo landing without demo session', function () {
    $response = $this->get('/admin/audit-logs');
    $response->assertRedirect(route('demo.landing'));
});

test('skips expensive swap when worker is already pointed at visitor DB', function () {
    $uuid = (string) Str::uuid();
    $dbName = 'demo_'.str_replace('-', '_', $uuid);

    // Simulate a prior request on the same Octane worker having already
    // swapped the demo connection to this visitor's database.
    Config::set('database.connections.demo.database', $dbName);

    $response = $this->withUnencryptedCookies(['demo_session' => $uuid.'|operator'])->get('/');

    // The fast path must not bounce the visitor to the landing page: that
    // response is reserved for a genuine missing-DB scenario and would wipe
    // the demo_session cookie.
    expect($response->isRedirect(route('demo.landing')))->toBeFalse();
});

test('middleware is no-op when demo mode is disabled', function () {
    Config::set('demo.enabled', false);
    // GET / renders the public landing for unauthenticated visitors (200).
    // The important assertion is that the middleware did NOT redirect to /demo.
    $response = $this->get('/');
    $response->assertStatus(200);
    expect($response->isRedirect())->toBeFalse();
});

/**
 * Treat every demo database as present, since the test suite runs on SQLite
 * and cannot create the per-visitor MySQL schemas.
 */
function assumeDemoDatabasesExist(): void
{
    Config::set('demo.shared_cache_store', 'array');

    app()->bind(DemoMiddleware::class, fn () => new class extends DemoMiddleware
    {
        protected function demoDatabaseExists(string $dbName): bool
        {
            return true;
        }
    });
}

test('re-authenticates an invited guest as their own account', function () {
    assumeDemoDatabasesExist();
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($role);
    $guest = User::factory()->create();
    $guest->assignRole($role);
    $uuid = (string) Str::uuid();

    $this->withUnencryptedCookies(['demo_session' => $uuid.'|operator'])
        ->withCookie(DemoController::GUEST_COOKIE, $uuid.'|'.$guest->id)
        ->get('/');

    $this->assertAuthenticatedAs($guest);
});

test('ignores a guest cookie issued for a different sandbox', function () {
    assumeDemoDatabasesExist();
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $persona = User::factory()->create();
    $persona->assignRole($role);
    $guest = User::factory()->create();
    $guest->assignRole($role);

    $this->withUnencryptedCookies(['demo_session' => Str::uuid().'|operator'])
        ->withCookie(DemoController::GUEST_COOKIE, Str::uuid().'|'.$guest->id)
        ->get('/');

    $this->assertAuthenticatedAs($persona);
});
