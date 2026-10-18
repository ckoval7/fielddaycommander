<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
    $response = $this->withCookies(['demo_session' => 'not-a-uuid'])->get('/');
    $response->assertRedirect(route('demo.landing'));
});

test('redirects to demo landing when demo database does not exist', function () {
    $uuid = (string) Str::uuid();
    $response = $this->withCookies(['demo_session' => $uuid])->get('/');
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

    $response = $this->withCookies(['demo_session' => $uuid.'|1'])->get('/');

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

test('signs the visitor in as the account in their cookie', function () {
    assumeDemoDatabasesExist();
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($role);
    $visitor = User::factory()->create();
    $visitor->assignRole($role);

    $this->withCookies(['demo_session' => Str::uuid().'|'.$visitor->id])
        ->get('/');

    $this->assertAuthenticatedAs($visitor);
});

test('does not fall back to another account when the visitor\'s is gone', function () {
    assumeDemoDatabasesExist();
    $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($role);

    $this->withCookies(['demo_session' => Str::uuid().'|999999'])
        ->get('/');

    $this->assertGuest();
});

test('ignores a demo_session cookie that was not encrypted by the app', function () {
    assumeDemoDatabasesExist();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']));

    $this->withUnencryptedCookies(['demo_session' => Str::uuid().'|'.$admin->id])
        ->get('/')
        ->assertRedirect(route('demo.landing'));

    $this->assertGuest();
});

test('sends a visitor whose account another participant locked to the landing page instead of looping', function () {
    assumeDemoDatabasesExist();
    $visitor = User::factory()->create(['account_locked_at' => now()]);
    $visitor->assignRole(Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']));
    $cookie = ['demo_session' => Str::uuid().'|'.$visitor->id];

    // A new worker (or any non-Octane request) re-signs the visitor in from the cookie.
    foreach (['/', '/login?account=locked'] as $path) {
        Config::set('database.connections.demo.database', 'demo_base');

        $this->withCookies($cookie)->get($path)
            ->assertRedirect(route('demo.landing', ['account' => 'locked']));

        $this->assertGuest();
    }

    // A worker already on the visitor's database finds them in the session.
    $this->actingAs($visitor);
    $this->withCookies($cookie)->get('/')
        ->assertRedirect(route('demo.landing', ['account' => 'locked']));
    $this->assertGuest();

    $this->get(route('demo.landing', ['account' => 'locked']))
        ->assertOk()
        ->assertSee(DemoMiddleware::LOCKED_MESSAGE);
});

test('tells Livewire requests from a locked visitor to go to the landing page', function () {
    assumeDemoDatabasesExist();
    $visitor = User::factory()->create(['account_locked_at' => now()]);

    $this->withCredentials()
        ->withCookies(['demo_session' => Str::uuid().'|'.$visitor->id])
        ->withHeader('X-Livewire', '1')
        ->getJson('/')
        ->assertStatus(419)
        ->assertJson(['redirect' => route('demo.landing', ['account' => 'locked'])]);
});
