<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\DemoEvent;
use App\Models\DemoParticipant;
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
    $this->withCredentials()->withCookie('demo_session', $this->uuid.'|1')
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
    $this->withCredentials()->withCookie('demo_session', $this->uuid.'|1')
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
    $this->withCredentials()->withCookie('demo_session', $unknownUuid.'|1')
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
        'event_type_distribution' => ['labels', 'data'],
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
        'session' => ['role', 'event_type', 'device_type', 'provisioned_at', 'last_seen_at', 'total_page_views', 'total_actions'],
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

it('includes visitor_id in session events response', function () {
    $url = URL::temporarySignedRoute(
        'demo.analytics.session-events',
        now()->addHour(),
        ['session' => $this->demoSession->id]
    );

    $response = $this->getJson($url)->assertOk();

    expect($response->json('session.visitor_id'))
        ->toBe(substr(hash('sha256', 'test'), 0, 8));
});

it('includes visitor_id in recent_sessions from api endpoint', function () {
    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $response = $this->getJson($url)->assertOk();

    expect($response->json('recent_sessions.0.visitor_id'))
        ->toBe(substr(hash('sha256', 'test'), 0, 8));
});

it('includes visitor_id in repeat_visitors from api endpoint', function () {
    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subHour(),
        'last_seen_at' => now(),
        'total_page_views' => 5,
        'expires_at' => now()->addHours(23),
    ]);

    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $response = $this->getJson($url)->assertOk();

    expect($response->json('repeat_visitors.0.visitor_id'))
        ->toBe(substr(hash('sha256', 'test'), 0, 8));
});

test('dashboard lists participants with the names and call signs they entered', function () {
    DemoParticipant::record($this->demoSession, ['role' => 'event_manager', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'call_sign' => 'k1ada'], viaInvite: false);
    DemoParticipant::record($this->demoSession, ['role' => 'operator'], viaInvite: true);

    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => '7d']);

    $this->get($url)
        ->assertOk()
        ->assertSee('Participants (2)')
        ->assertSee('Ada Lovelace')
        ->assertSee('K1ADA')
        ->assertSee('Started sandbox')
        ->assertSee('Invited');
});

test('api includes participants', function () {
    DemoParticipant::record($this->demoSession, ['role' => 'operator', 'first_name' => 'Ada', 'call_sign' => 'K1ADA'], viaInvite: true);

    $url = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('participants.0.name', 'Ada')
        ->assertJsonPath('participants.0.call_sign', 'K1ADA')
        ->assertJsonPath('participants.0.via_invite', true)
        ->assertJsonPath('participants.0.visitor_id', $this->demoSession->visitor_id);
});

test('session events include the session\'s participants', function () {
    DemoParticipant::record($this->demoSession, ['role' => 'operator', 'call_sign' => 'K1ADA'], viaInvite: false);

    $url = URL::temporarySignedRoute('demo.analytics.session-events', now()->addHour(), ['session' => $this->demoSession->id]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('participants.0.name', null)
        ->assertJsonPath('participants.0.call_sign', 'K1ADA');
});

test('all time range includes sessions and participants older than 90 days', function () {
    $oldSession = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'old'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subYear(),
        'last_seen_at' => now()->subYear(),
        'expires_at' => now()->subYear()->addDay(),
    ]);
    DemoParticipant::record($oldSession, ['role' => 'system_admin', 'first_name' => 'Ada'], viaInvite: false);

    $ninetyDays = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '90d']);
    $allTime = URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => 'all']);

    $this->getJson($ninetyDays)
        ->assertJsonPath('overview.total_sessions', 1)
        ->assertJsonCount(0, 'participants');

    $this->getJson($allTime)
        ->assertJsonPath('range', 'all')
        ->assertJsonPath('overview.total_sessions', 2)
        ->assertJsonPath('participants.0.name', 'Ada');
});

test('dashboard offers an all time range', function () {
    $url = URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => 'all']);

    $this->get($url)
        ->assertOk()
        ->assertSee('All time')
        ->assertSee('All Sessions (All time)');
});

it('breaks analytics down by the event type each visitor chose', function () {
    foreach (['WFD', 'WFD'] as $eventType) {
        DemoSession::create([
            'session_uuid' => fake()->uuid(),
            'role' => 'operator',
            'event_type' => $eventType,
            'visitor_hash' => hash('sha256', 'test'),
            'user_agent' => 'Test',
            'device_type' => 'desktop',
            'provisioned_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    $response = $this->getJson(URL::temporarySignedRoute('demo.analytics.api', now()->addHour(), ['range' => '7d']))
        ->assertOk();

    expect($response->json('event_type_distribution'))->toBe([
        'labels' => ['Winter Field Day', 'ARRL Field Day'],
        'data' => [2, 1],
    ])
        ->and(collect($response->json('recent_sessions'))->pluck('event_type')->sort()->values()->all())->toBe(['FD', 'WFD', 'WFD'])
        ->and($response->json('repeat_visitors.0.event_types_tried'))->toEqualCanonicalizing(['FD', 'WFD']);

    $this->get(URL::temporarySignedRoute('demo.analytics.dashboard', now()->addHour(), ['range' => '7d']))
        ->assertOk()
        ->assertSee('Event Type')
        ->assertSee('Winter Field Day');
});
