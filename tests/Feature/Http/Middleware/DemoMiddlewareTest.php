<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
