<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\DemoDatabasePool;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// Tests here that stand in for the DB facade leave the mock bound while the
// app tears down, which asks it for the open connections.
afterEach(function () {
    if (DB::getFacadeRoot() instanceof Mockery\MockInterface) {
        DB::shouldReceive('getConnections')->andReturn([])->byDefault();
    }
});

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
    // Most tests here are about which sandboxes count as idle, not about
    // whether the demo is busy enough to drop them; see the busy tests below.
    Config::set('demo.early_drop_percent', 0);
});

test('cleanup command runs successfully with no demo databases', function () {
    DB::shouldReceive('select')
        ->once()
        ->andReturn([]);

    $this->artisan('demo:cleanup')
        ->assertSuccessful();
});

test('cleanup command outputs message when no databases are found', function () {
    DB::shouldReceive('select')
        ->once()
        ->andReturn([]);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain('No expired demo databases')
        ->assertSuccessful();
});

test('cleanup command exits early when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain('Demo mode is disabled')
        ->assertSuccessful();
});

test('cleanup sweeps abandoned unfinished databases', function () {
    DB::shouldReceive('select')->once()->andReturn([(object) ['schema_name' => 'demo_unexpected']]);
    DB::shouldReceive('purge');

    $this->mock(DemoDatabasePool::class)
        ->shouldReceive('dropOrphans')
        ->once()
        ->with(['demo_unexpected'])
        ->andReturn(['demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa']);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain('Dropped orphaned demo database: demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa')
        ->assertSuccessful();
});

test('cleanup does not pass databases it just dropped as expired to the orphan sweep', function () {
    $expired = 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa';
    $unfinished = 'demo_bbbbbbbb_bbbb_bbbb_bbbb_bbbbbbbbbbbb';

    DB::shouldReceive('select')->once()->andReturn([
        (object) ['schema_name' => $expired],
        (object) ['schema_name' => $unfinished],
    ]);
    DB::shouldReceive('purge');

    $schema = Mockery::mock();
    $schema->shouldReceive('hasTable')->with('system_config')->andReturn(true, false);

    $connection = Mockery::mock();
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('table->where->value')->andReturn(now()->subDays(2)->toDateTimeString());
    $connection->shouldReceive('statement')->once()->with("DROP DATABASE `{$expired}`");
    DB::shouldReceive('connection')->with('demo')->andReturn($connection);

    $this->mock(DemoDatabasePool::class)
        ->shouldReceive('dropOrphans')
        ->once()
        ->with([$unfinished])
        ->andReturn([]);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain("Dropped expired demo database: {$expired}")
        ->assertSuccessful();
});

/**
 * Stand in for the TTL sweep's look inside each schema: every database here
 * is still within its TTL, so only the idle sweep can drop it.
 *
 * @param  list<string>  $databases
 */
function demoSchemasWithinTtl(array $databases): void
{
    DB::shouldReceive('select')->once()->andReturn(
        array_map(fn (string $name): object => (object) ['schema_name' => $name], $databases),
    );
    DB::shouldReceive('purge');

    $schema = Mockery::mock();
    $schema->shouldReceive('hasTable')->with('system_config')->andReturn(true);

    $connection = Mockery::mock();
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('table->where->value')->andReturn(now()->toDateTimeString());
    DB::shouldReceive('connection')->with('demo')->andReturn($connection);
}

function cleanupDemoSession(string $uuid, Carbon\CarbonInterface $lastSeenAt, ?Carbon\CarbonInterface $expiresAt = null, int $pageViews = 5): App\Models\DemoSession
{
    return App\Models\DemoSession::create([
        'session_uuid' => $uuid,
        'role' => 'operator',
        'event_type' => 'FD',
        'visitor_hash' => str_repeat('a', 64),
        'user_agent' => 'Pest',
        'provisioned_at' => now()->subHours(5),
        'last_seen_at' => $lastSeenAt,
        'expires_at' => $expiresAt ?? now()->addHours(19),
        'total_page_views' => $pageViews,
    ]);
}

test('cleanup drops a sandbox nobody has touched for the idle timeout', function () {
    Config::set('demo.idle_hours', 3);
    $idleUuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $activeUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $idle = cleanupDemoSession($idleUuid, now()->subHours(4));
    $active = cleanupDemoSession($activeUuid, now()->subMinutes(10));

    demoSchemasWithinTtl([
        DemoDatabasePool::databaseName($idleUuid),
        DemoDatabasePool::databaseName($activeUuid),
    ]);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldReceive('dropSandbox')->once()->with($idleUuid);
    $pool->shouldReceive('dropOrphans')->once()->with([DemoDatabasePool::databaseName($activeUuid)])->andReturn([]);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain('Dropped idle demo database: '.DemoDatabasePool::databaseName($idleUuid))
        ->assertSuccessful();

    expect($idle->fresh()->hasExpired())->toBeTrue()
        ->and($active->fresh()->hasExpired())->toBeFalse();
});

test('the idle sweep skips sandboxes whose database is already gone', function () {
    Config::set('demo.idle_hours', 3);
    cleanupDemoSession('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', now()->subHours(4));

    demoSchemasWithinTtl([]);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldNotReceive('dropSandbox');

    $this->artisan('demo:cleanup')->assertSuccessful();
});

test('an idle timeout of zero keeps idle sandboxes for the full TTL', function () {
    Config::set('demo.idle_hours', 0);
    $uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    cleanupDemoSession($uuid, now()->subHours(20));

    demoSchemasWithinTtl([DemoDatabasePool::databaseName($uuid)]);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldNotReceive('dropSandbox');
    $pool->shouldReceive('dropOrphans')->andReturn([]);

    $this->artisan('demo:cleanup')->assertSuccessful();
});

test('cleanup drops a sandbox never used past its first page well before the idle timeout', function () {
    Config::set('demo.idle_hours', 3);
    Config::set('demo.unused_minutes', 30);
    $unusedUuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $browsedUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $freshUuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    cleanupDemoSession($unusedUuid, now()->subMinutes(45), pageViews: 1);
    cleanupDemoSession($browsedUuid, now()->subMinutes(45), pageViews: 2);
    cleanupDemoSession($freshUuid, now()->subMinutes(10), pageViews: 1);

    demoSchemasWithinTtl(array_map(DemoDatabasePool::databaseName(...), [$unusedUuid, $browsedUuid, $freshUuid]));

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldReceive('dropSandbox')->once()->with($unusedUuid);
    $pool->shouldReceive('dropOrphans')->andReturn([]);

    $this->artisan('demo:cleanup')->assertSuccessful();
});

test('an unused timeout of zero leaves unused sandboxes to the idle timeout', function () {
    Config::set('demo.idle_hours', 3);
    Config::set('demo.unused_minutes', 0);
    $uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    cleanupDemoSession($uuid, now()->subMinutes(90), pageViews: 1);

    demoSchemasWithinTtl([DemoDatabasePool::databaseName($uuid)]);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldNotReceive('dropSandbox');
    $pool->shouldReceive('dropOrphans')->andReturn([]);

    $this->artisan('demo:cleanup')->assertSuccessful();
});

test('a quiet demo keeps idle and unused sandboxes for their full TTL', function () {
    Config::set('demo.max_sessions', 10);
    Config::set('demo.early_drop_percent', 70);
    $idle = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $pooled = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    cleanupDemoSession($idle, now()->subHours(5), pageViews: 1);

    // Six schemas but one is an unclaimed pool database: five of ten seats, under 70%.
    $names = [DemoDatabasePool::databaseName($idle), DemoDatabasePool::databaseName($pooled)];
    foreach (range(1, 4) as $i) {
        $names[] = DemoDatabasePool::databaseName(sprintf('%08d-0000-0000-0000-000000000000', $i));
    }
    demoSchemasWithinTtl($names);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([DemoDatabasePool::databaseName($pooled)]);
    $pool->shouldNotReceive('dropSandbox');
    $pool->shouldReceive('dropOrphans')->andReturn([]);

    $this->artisan('demo:cleanup')
        ->expectsOutputToContain('Demo is quiet (5 of 10 seats taken)')
        ->assertSuccessful();
});

test('a busy demo drops idle sandboxes early', function () {
    Config::set('demo.max_sessions', 10);
    Config::set('demo.early_drop_percent', 70);
    $idle = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    cleanupDemoSession($idle, now()->subHours(5), pageViews: 1);

    $names = [DemoDatabasePool::databaseName($idle)];
    foreach (range(1, 6) as $i) {
        $names[] = DemoDatabasePool::databaseName(sprintf('%08d-0000-0000-0000-000000000000', $i));
    }
    demoSchemasWithinTtl($names);

    $pool = $this->mock(DemoDatabasePool::class);
    $pool->shouldReceive('pooledDatabaseNames')->andReturn([]);
    $pool->shouldReceive('dropSandbox')->once()->with($idle);
    $pool->shouldReceive('dropOrphans')->andReturn([]);

    $this->artisan('demo:cleanup')->assertSuccessful();
});
