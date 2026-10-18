<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\DemoEvent;
use App\Models\DemoParticipant;
use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;

it('prunes analytics sessions and their events older than the retention period', function () {
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
        'created_at' => now()->subDays(60),
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

    expect(DemoSession::sole()->id)->toBe($recentSession->id)
        ->and(DemoEvent::sole()->demo_session_id)->toBe($recentSession->id);
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

it('prunes participants along with sessions older than the retention period', function () {
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

    $recentSession = DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'recent'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(5),
        'last_seen_at' => now()->subDays(5),
        'expires_at' => now()->subDays(4),
    ]);

    DemoParticipant::record($oldSession, ['role' => 'operator', 'first_name' => 'Ada', 'call_sign' => 'K1ADA'], viaInvite: false);
    DemoParticipant::record($recentSession, ['role' => 'operator', 'first_name' => 'Grace', 'call_sign' => 'K1GMH'], viaInvite: false);

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(DemoParticipant::sole())
        ->demo_session_id->toBe($recentSession->id)
        ->call_sign->toBe('K1GMH');
});
