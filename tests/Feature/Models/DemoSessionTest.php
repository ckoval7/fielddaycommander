<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;

it('creates a demo session with required fields', function () {
    $session = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test-ip-salt'),
        'user_agent' => 'Mozilla/5.0 Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    expect($session)->toBeInstanceOf(DemoSession::class)
        ->and($session->id)->toBeGreaterThan(0)
        ->and($session->total_page_views)->toBe(0)
        ->and($session->total_actions)->toBe(0)
        ->and($session->was_reset)->toBeFalse();
});

it('has many demo events', function () {
    $session = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'test-ip-salt'),
        'user_agent' => 'Mozilla/5.0 Test',
        'device_type' => 'mobile',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    DemoEvent::create([
        'demo_session_id' => $session->id,
        'type' => 'page_view',
        'name' => 'dashboard.index',
        'route_name' => 'dashboard',
    ]);

    DemoEvent::create([
        'demo_session_id' => $session->id,
        'type' => 'action',
        'name' => 'contact.logged',
        'metadata' => ['band' => '20m'],
    ]);

    expect($session->events)->toHaveCount(2)
        ->and($session->events->first()->type)->toBe('page_view');
});

it('casts provisioned_at and expires_at as datetimes', function () {
    $session = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => '2026-04-07 12:00:00',
        'last_seen_at' => '2026-04-07 12:00:00',
        'expires_at' => '2026-04-08 12:00:00',
    ]);

    expect($session->provisioned_at)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($session->expires_at)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($session->was_reset)->toBeBool();
});

it('parses device type from user agent', function () {
    expect(DemoSession::parseDeviceType('Mozilla/5.0 (iPhone; CPU iPhone OS 16_0)'))
        ->toBe('mobile')
        ->and(DemoSession::parseDeviceType('Mozilla/5.0 (iPad; CPU OS 16_0)'))
        ->toBe('tablet')
        ->and(DemoSession::parseDeviceType('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'))
        ->toBe('desktop');
});

it('computes visitor hash from ip and app key', function () {
    config(['app.key' => 'base64:testkey123456789012345678901234']);
    $hash = DemoSession::visitorHash('192.168.1.1');

    expect($hash)->toBeString()
        ->toHaveLength(64)
        ->and(DemoSession::visitorHash('192.168.1.1'))->toBe($hash)
        ->and(DemoSession::visitorHash('10.0.0.1'))->not->toBe($hash);
});

it('cascades deletes to events', function () {
    $session = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    DemoEvent::create([
        'demo_session_id' => $session->id,
        'type' => 'page_view',
        'name' => 'test.page',
    ]);

    $session->delete();

    expect(DemoEvent::where('demo_session_id', $session->id)->count())->toBe(0);
});
