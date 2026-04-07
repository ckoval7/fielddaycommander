<?php

use App\Http\Middleware\DemoAnalyticsMiddleware;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['demo.enabled' => true]);
});

it('tracks page view for demo session', function () {
    $uuid = fake()->uuid();
    $session = DemoSession::create([
        'session_uuid' => $uuid,
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now()->subMinutes(5),
        'expires_at' => now()->addHours(24),
    ]);

    $request = Request::create('/dashboard', 'GET');
    $request->cookies->set('demo_session', $uuid.'|operator');

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    $session->refresh();
    expect($session->total_page_views)->toBeGreaterThanOrEqual(1)
        ->and($session->last_seen_at->isAfter(now()->subMinute()))->toBeTrue();

    expect(DemoEvent::where('demo_session_id', $session->id)
        ->where('type', 'page_view')
        ->exists()
    )->toBeTrue();
});

it('skips tracking when demo mode is disabled', function () {
    config(['demo.enabled' => false]);

    $request = Request::create('/dashboard', 'GET');

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    expect(DemoEvent::count())->toBe(0);
});

it('skips tracking for demo landing route', function () {
    Route::getRoutes()->refreshNameLookups();

    $request = Request::create(route('demo.landing'), 'GET');
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    expect(DemoEvent::count())->toBe(0);
});

it('skips tracking when no demo session cookie exists', function () {
    $request = Request::create('/dashboard', 'GET');

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    expect(DemoEvent::count())->toBe(0);
});

it('skips tracking when demo session not found in database', function () {
    $uuid = fake()->uuid();

    $request = Request::create('/dashboard', 'GET');
    $request->cookies->set('demo_session', $uuid.'|operator');

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    expect(DemoEvent::count())->toBe(0);
});

it('skips tracking when cookie has invalid uuid format', function () {
    $request = Request::create('/dashboard', 'GET');
    $request->cookies->set('demo_session', 'not-a-uuid|operator');

    $middleware = app(DemoAnalyticsMiddleware::class);
    $middleware->handle($request, fn ($req) => response('ok'));

    expect(DemoEvent::count())->toBe(0);
});
