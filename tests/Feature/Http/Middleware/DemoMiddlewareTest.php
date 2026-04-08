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

test('allows login page through without demo session', function () {
    $response = $this->get('/login');
    $response->assertOk();
});

test('allows admin routes through without demo session', function () {
    $response = $this->get('/admin/demo-analytics');
    // Should hit auth middleware (302 to login), not demo redirect
    $response->assertRedirect('/login');
});

test('blocks demo users from admin routes', function () {
    $uuid = (string) Str::uuid();
    $cookie = $uuid.'|system_admin';
    $response = $this->withUnencryptedCookies(['demo_session' => $cookie])->get('/admin/demo-analytics');
    $response->assertForbidden();
});

test('middleware is no-op when demo mode is disabled', function () {
    Config::set('demo.enabled', false);
    // GET / renders the public landing for unauthenticated visitors (200).
    // The important assertion is that the middleware did NOT redirect to /demo.
    $response = $this->get('/');
    $response->assertStatus(200);
    expect($response->isRedirect())->toBeFalse();
});
