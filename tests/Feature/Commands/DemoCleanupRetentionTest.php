<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;

it('prunes analytics data older than retention period', function () {
    config(['demo.enabled' => true, 'demo.analytics_retention_days' => 30]);

    DB::shouldReceive('select')->once()->andReturn([]);

    $oldSession = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'old'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(60),
        'last_seen_at' => now()->subDays(60),
        'expires_at' => now()->subDays(59),
    ]);

    DemoEvent::create([
        'demo_session_id' => $oldSession->id,
        'type' => 'page_view',
        'name' => 'old.page',
    ]);

    $recentSession = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'recent'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(5),
        'last_seen_at' => now()->subDays(5),
        'expires_at' => now()->subDays(4),
    ]);

    DemoEvent::create([
        'demo_session_id' => $recentSession->id,
        'type' => 'page_view',
        'name' => 'recent.page',
    ]);

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(DemoSession::count())->toBe(1)
        ->and(DemoSession::first()->id)->toBe($recentSession->id)
        ->and(DemoEvent::count())->toBe(1);
});

it('does not prune when all data is within retention', function () {
    config(['demo.enabled' => true, 'demo.analytics_retention_days' => 90]);

    DB::shouldReceive('select')->once()->andReturn([]);

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'recent'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(30),
        'last_seen_at' => now()->subDays(30),
        'expires_at' => now()->subDays(29),
    ]);

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(DemoSession::count())->toBe(1);
});
