<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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
    $response = $this->post(route('demo.provision'), ['role' => 'supervillain']);
    $response->assertSessionHasErrors('role');
});

test('provision redirects back with error when session cap is reached', function () {
    Config::set('demo.max_sessions', 0);
    $response = $this->post(route('demo.provision'), ['role' => 'operator']);
    $response->assertRedirect(route('demo.landing'));
    $response->assertSessionHas('error');
});

test('reset marks session as reset and logs role switch', function () {
    $uuid = fake()->uuid();
    $demoSession = DemoSession::create([
        'session_uuid' => $uuid,
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    $previousRole = $demoSession->role;
    $demoSession->update([
        'was_reset' => true,
        'role' => 'system_admin',
        'last_seen_at' => now(),
    ]);

    DemoEvent::create([
        'demo_session_id' => $demoSession->id,
        'type' => 'action',
        'name' => 'role.switched',
        'metadata' => ['from_role' => $previousRole, 'to_role' => 'system_admin'],
    ]);

    $demoSession->refresh();
    expect($demoSession->was_reset)->toBeTrue()
        ->and($demoSession->role)->toBe('system_admin');

    expect(DemoEvent::where('demo_session_id', $demoSession->id)
        ->where('name', 'role.switched')
        ->exists()
    )->toBeTrue();

    $event = DemoEvent::where('name', 'role.switched')->first();
    expect($event->metadata)->toBe(['from_role' => 'operator', 'to_role' => 'system_admin']);
});
