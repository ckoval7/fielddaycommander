<?php

use App\Models\DemoPoolDatabase;
use App\Services\DemoDatabasePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.shared_cache_store', 'array');
    Config::set('demo.provision_concurrency', 2);
    Config::set('demo.provision_wait_seconds', 0);
});

/**
 * A pool whose database-level operations are faked, so the bookkeeping can
 * be exercised without a MySQL server.
 *
 * @param  list<string>  $existingDatabases
 */
function fakeDemoPool(array $existingDatabases = []): DemoDatabasePool
{
    $pool = Mockery::mock(DemoDatabasePool::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $pool->shouldReceive('existingDemoDatabases')->andReturn($existingDatabases);

    return $pool;
}

test('database name is derived from the session uuid', function () {
    expect(DemoDatabasePool::databaseName('0f8fad5b-d9cb-469f-a165-70867728950e'))
        ->toBe('demo_0f8fad5b_d9cb_469f_a165_70867728950e');
});

test('database name rejects identifiers that are not uuids', function () {
    DemoDatabasePool::databaseName('x`; DROP DATABASE prod; --');
})->throws(InvalidArgumentException::class);

test('claim hands out the oldest pooled database and removes it from the pool', function () {
    $first = DemoPoolDatabase::create(['session_uuid' => fake()->uuid()]);
    $second = DemoPoolDatabase::create(['session_uuid' => fake()->uuid()]);

    $pool = new DemoDatabasePool;

    expect($pool->claim())->toBe($first->session_uuid)
        ->and($pool->claim())->toBe($second->session_uuid)
        ->and($pool->claim())->toBeNull();
});

test('pooled database names cover every unclaimed database', function () {
    $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
    DemoPoolDatabase::create(['session_uuid' => $uuid]);

    expect((new DemoDatabasePool)->pooledDatabaseNames())
        ->toBe(['demo_0f8fad5b_d9cb_469f_a165_70867728950e']);
});

test('fill builds databases until the pool reaches its target', function () {
    $pool = fakeDemoPool();
    $pool->shouldReceive('createDatabase')->times(3)->andReturnUsing(fn () => fake()->uuid());
    $pool->shouldReceive('migrate')->times(3);

    $result = $pool->fill(3);

    expect($result)->toBe(['created' => 3, 'dropped' => 0, 'forgotten' => 0])
        ->and(DemoPoolDatabase::count())->toBe(3);
});

test('fill forgets pooled entries whose database no longer exists', function () {
    $kept = DemoPoolDatabase::create(['session_uuid' => fake()->uuid()]);
    DemoPoolDatabase::create(['session_uuid' => fake()->uuid()]);

    $pool = fakeDemoPool([DemoDatabasePool::databaseName($kept->session_uuid)]);
    $pool->shouldReceive('createDatabase')->once()->andReturnUsing(fn () => fake()->uuid());
    $pool->shouldReceive('migrate')->once();

    $result = $pool->fill(2);

    expect($result)->toBe(['created' => 1, 'dropped' => 0, 'forgotten' => 1])
        ->and(DemoPoolDatabase::where('session_uuid', $kept->session_uuid)->exists())->toBeTrue();
});

test('fill drops surplus databases when the target shrinks', function () {
    $entries = collect(range(1, 3))->map(fn () => DemoPoolDatabase::create(['session_uuid' => fake()->uuid()]));

    $pool = fakeDemoPool($entries->map(fn ($entry) => DemoDatabasePool::databaseName($entry->session_uuid))->all());
    $pool->shouldReceive('dropDatabase')->twice();
    $pool->shouldNotReceive('createDatabase');

    $result = $pool->fill(1);

    expect($result)->toBe(['created' => 0, 'dropped' => 2, 'forgotten' => 0])
        ->and(DemoPoolDatabase::count())->toBe(1);
});

test('fill drops a database whose migration fails and never pools it', function () {
    $uuid = fake()->uuid();

    $pool = fakeDemoPool();
    $pool->shouldReceive('createDatabase')->once()->andReturn($uuid);
    $pool->shouldReceive('migrate')->once()->andThrow(new RuntimeException('migration failed'));
    $pool->shouldReceive('dropDatabase')->once()->with(DemoDatabasePool::databaseName($uuid), Mockery::any());

    expect(fn () => $pool->fill(1))->toThrow(RuntimeException::class, 'migration failed');
    expect(DemoPoolDatabase::count())->toBe(0);
});

test('a provisioning slot is granted while one is free', function () {
    $pool = new DemoDatabasePool;

    $first = $pool->acquireProvisioningSlot();
    $second = $pool->acquireProvisioningSlot();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($pool->acquireProvisioningSlot())->toBeNull();

    $first->release();

    expect($pool->acquireProvisioningSlot())->not->toBeNull();
});

test('no slot is granted while every slot is held elsewhere', function () {
    Cache::store('array')->lock('demo:provision-slot:1', 60)->get();
    Cache::store('array')->lock('demo:provision-slot:2', 60)->get();

    expect((new DemoDatabasePool)->acquireProvisioningSlot())->toBeNull();
});

test('an unfinished database is only marked on the first sweep', function () {
    $dbName = 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa';

    $pool = fakeDemoPool();
    $pool->shouldReceive('isProvisioned')->with($dbName)->andReturnFalse();
    $pool->shouldNotReceive('dropDatabase');

    expect($pool->dropOrphans([$dbName]))->toBe([]);
});

test('an unfinished database is dropped once it stays unfinished past the grace period', function () {
    $dbName = 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa';

    $pool = fakeDemoPool();
    $pool->shouldReceive('isProvisioned')->with($dbName)->andReturnFalse();
    $pool->shouldReceive('dropDatabase')->once()->with($dbName, Mockery::any());

    $pool->dropOrphans([$dbName]);

    $this->travel(20)->minutes();
    expect($pool->dropOrphans([$dbName]))->toBe([]);

    $this->travel(40)->minutes();
    expect($pool->dropOrphans([$dbName]))->toBe([$dbName]);
});

test('a database that finishes provisioning between sweeps is spared', function () {
    $dbName = 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa';

    $pool = fakeDemoPool();
    $pool->shouldReceive('isProvisioned')->with($dbName)->andReturn(false, true, false);
    $pool->shouldNotReceive('dropDatabase');

    $pool->dropOrphans([$dbName]);
    $this->travel(2)->hours();
    $pool->dropOrphans([$dbName]);

    // Finishing reset the clock, so this sighting only marks it again.
    $this->travel(2)->hours();
    expect($pool->dropOrphans([$dbName]))->toBe([]);
});

test('pooled databases and unexpected schema names are never treated as orphans', function () {
    $pooledUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    DemoPoolDatabase::create(['session_uuid' => $pooledUuid]);

    $pool = fakeDemoPool();
    $pool->shouldNotReceive('isProvisioned');
    $pool->shouldNotReceive('dropDatabase');

    $names = ['demo_base', DemoDatabasePool::databaseName($pooledUuid)];

    $pool->dropOrphans($names);
    $this->travel(2)->hours();

    expect($pool->dropOrphans($names))->toBe([]);
});
