<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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
