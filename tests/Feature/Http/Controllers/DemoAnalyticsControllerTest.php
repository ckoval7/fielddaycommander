<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);

    $this->uuid = fake()->uuid();
    $this->demoSession = DemoSession::create([
        'session_uuid' => $this->uuid,
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);
});

it('records a time_on_page beacon event', function () {
    $this->withCredentials()->withUnencryptedCookie('demo_session', $this->uuid.'|operator')
        ->postJson(route('demo.analytics.beacon'), [
            'page' => '/dashboard',
            'seconds' => 42,
            'route' => 'dashboard',
        ])
        ->assertNoContent();

    $event = DemoEvent::where('demo_session_id', $this->demoSession->id)
        ->where('name', 'time_on_page')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe('client')
        ->and($event->metadata)->toBe(['seconds' => 42, 'page' => '/dashboard'])
        ->and($event->route_name)->toBe('dashboard');
});

it('rejects beacon with invalid seconds', function () {
    $this->withCredentials()->withUnencryptedCookie('demo_session', $this->uuid.'|operator')
        ->postJson(route('demo.analytics.beacon'), [
            'page' => '/dashboard',
            'seconds' => -5,
        ])
        ->assertUnprocessable();
});

it('rejects beacon when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson(route('demo.analytics.beacon'), [
        'page' => '/dashboard',
        'seconds' => 10,
    ])->assertNotFound();
});

it('returns no content when session not found', function () {
    $unknownUuid = fake()->uuid();
    $this->withCredentials()->withUnencryptedCookie('demo_session', $unknownUuid.'|operator')
        ->postJson(route('demo.analytics.beacon'), [
            'page' => '/dashboard',
            'seconds' => 10,
        ])
        ->assertNoContent();

    expect(DemoEvent::count())->toBe(0);
});

it('serves the analytics dashboard via signed URL', function () {
    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => '7d']);

    $this->get($url)->assertOk()->assertSee('Demo Analytics');
});

it('rejects unsigned requests to the analytics dashboard', function () {
    $this->get(route('demo.analytics.dashboard', ['range' => '7d']))->assertForbidden();
});

it('rejects expired signed URLs for the analytics dashboard', function () {
    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->subMinute(), ['range' => '7d']);

    $this->get($url)->assertForbidden();
});

it('serves the analytics api via signed URL', function () {
    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $this->getJson($url)->assertOk()->assertJson(['range' => '7d']);
});

it('returns JSON analytics via signed API URL', function () {
    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subHour(),
        'last_seen_at' => now(),
        'total_page_views' => 10,
        'total_actions' => 3,
        'expires_at' => now()->addHours(23),
    ]);

    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $response = $this->getJson($url)->assertOk();

    $response->assertJsonStructure([
        'range',
        'generated_at',
        'overview' => [
            'total_sessions',
            'unique_visitors',
            'repeat_visitor_rate',
            'avg_duration_minutes',
            'bounce_rate',
        ],
        'role_distribution' => ['labels', 'data'],
        'session_funnel',
        'page_popularity',
        'feature_engagement',
        'time_on_page',
        'repeat_visitors',
        'recent_sessions',
    ]);

    expect($response->json('overview.total_sessions'))->toBe(2)
        ->and($response->json('range'))->toBe('7d');
});

it('rejects unsigned API requests', function () {
    $this->getJson(route('demo.analytics.api', ['range' => '7d']))->assertForbidden();
});

it('filters analytics by date range', function () {
    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'old'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(10),
        'last_seen_at' => now()->subDays(10),
        'total_page_views' => 5,
        'expires_at' => now()->subDays(9),
    ]);

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'recent'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDay(),
        'last_seen_at' => now(),
        'total_page_views' => 10,
        'expires_at' => now()->addHours(23),
    ]);

    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $response = $this->getJson($url)->assertOk();

    expect($response->json('overview.total_sessions'))->toBe(2);
});

it('defaults to 7d range when range param is missing', function () {
    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour());

    $response = $this->getJson($url)->assertOk();

    expect($response->json('range'))->toBe('7d');
});

it('defaults to 7d range when range param is invalid', function () {
    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => 'bogus']);

    $response = $this->getJson($url)->assertOk();

    expect($response->json('range'))->toBe('7d');
});

it('returns 404 for API when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $this->getJson($url)->assertNotFound();
});

it('returns session events via signed URL', function () {
    $event1 = DemoEvent::create([
        'demo_session_id' => $this->demoSession->id,
        'type' => 'page_view',
        'name' => 'home',
        'route_name' => 'home',
        'metadata' => ['path' => '/', 'method' => 'GET'],
        'created_at' => $this->demoSession->provisioned_at->addSeconds(5),
    ]);

    $event2 = DemoEvent::create([
        'demo_session_id' => $this->demoSession->id,
        'type' => 'action',
        'name' => 'contact.logged',
        'metadata' => ['band' => '20m', 'callsign' => 'W1AW'],
        'created_at' => $this->demoSession->provisioned_at->addMinutes(2),
    ]);

    $event3 = DemoEvent::create([
        'demo_session_id' => $this->demoSession->id,
        'type' => 'client',
        'name' => 'time_on_page',
        'metadata' => ['seconds' => 30, 'page' => '/dashboard'],
        'created_at' => $this->demoSession->provisioned_at->addMinutes(3),
    ]);

    $url = URL::temporarySignedRoute(
        'demo.analytics.session-events',
        now()->addHour(),
        ['session' => $this->demoSession->id]
    );

    $response = $this->getJson($url)->assertOk();

    $response->assertJsonStructure([
        'session' => ['role', 'device_type', 'provisioned_at', 'last_seen_at', 'total_page_views', 'total_actions'],
        'events' => [['type', 'name', 'route_name', 'metadata', 'created_at', 'seconds_from_start']],
    ]);

    $events = $response->json('events');
    expect($events)->toHaveCount(3)
        ->and($events[0]['type'])->toBe('page_view')
        ->and($events[0]['seconds_from_start'])->toBe(5)
        ->and($events[1]['type'])->toBe('action')
        ->and($events[1]['name'])->toBe('contact.logged')
        ->and($events[2]['type'])->toBe('client');
});

it('rejects unsigned requests to session events', function () {
    $this->getJson(route('demo.analytics.session-events', ['session' => $this->demoSession->id]))
        ->assertForbidden();
});

it('returns 404 for session events when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $url = URL::temporarySignedRoute(
        'demo.analytics.session-events',
        now()->addHour(),
        ['session' => $this->demoSession->id]
    );

    $this->getJson($url)->assertNotFound();
});

it('passes session event URLs to the dashboard view', function () {
    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => '7d']);

    $response = $this->get($url)->assertOk();

    $response->assertSee('Session Timelines');
});

it('displays overview metrics in the dashboard', function () {
    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'visitor1'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subHours(2),
        'last_seen_at' => now()->subHour(),
        'total_page_views' => 15,
        'total_actions' => 5,
        'expires_at' => now()->addHours(22),
    ]);

    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => '7d']);

    $this->get($url)
        ->assertOk()
        ->assertSee('Total Sessions')
        ->assertSee('Unique Visitors')
        ->assertSee('Bounce Rate')
        ->assertSee('Role Distribution')
        ->assertSee('Session Funnel')
        ->assertSee('Recent Sessions');
});
