<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Services\DemoDatabasePool;
use App\Services\DemoSeatReclaimer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    Config::set('demo.max_sessions', 4);
    Config::set('demo.reclaim_idle_minutes', 10);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function heldSandbox(string $holder, int $idleMinutes, array $attributes = []): DemoSession
{
    return DemoSession::create(array_merge([
        'session_uuid' => (string) Str::uuid(),
        'role' => 'operator',
        'event_type' => 'FD',
        'visitor_hash' => $holder,
        'user_agent' => 'Pest',
        'provisioned_at' => now()->subHour(),
        'last_seen_at' => now()->subMinutes($idleMinutes),
        'expires_at' => now()->addHours(23),
    ], $attributes));
}

test('a newcomer takes the least recently used idle sandbox from an address holding every seat', function () {
    heldSandbox('hog', 20);
    $oldest = heldSandbox('hog', 50);
    heldSandbox('hog', 30);
    heldSandbox('hog', 40);

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldReceive('dropSandbox')->once()->with($oldest->session_uuid);

    expect((new DemoSeatReclaimer($pool))->reclaimFor('newcomer'))->toBe($oldest->session_uuid)
        ->and($oldest->fresh()->hasExpired())->toBeTrue()
        ->and(DemoEvent::where('name', 'session.reclaimed')->sole()->demo_session_id)->toBe($oldest->id);
});

test('sandboxes in use are never taken, even from an address over its share', function () {
    foreach (range(1, 4) as $i) {
        heldSandbox('club', 2);
    }

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldNotReceive('dropSandbox');

    expect((new DemoSeatReclaimer($pool))->reclaimFor('newcomer'))->toBeNull();
});

test('only an address over its fair share gives up a seat', function () {
    // Four seats between three addresses and the newcomer: one each.
    $a = heldSandbox('a', 60);
    $b = heldSandbox('b', 60);
    heldSandbox('c', 60);
    heldSandbox('c', 60);

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldReceive('dropSandbox')->once()->with(Mockery::on(
        fn (string $uuid): bool => DemoSession::where('session_uuid', $uuid)->value('visitor_hash') === 'c',
    ));

    (new DemoSeatReclaimer($pool))->reclaimFor('newcomer');

    expect($a->fresh()->hasExpired())->toBeFalse()
        ->and($b->fresh()->hasExpired())->toBeFalse();
});

test('a visitor already holding their share cannot take seats from others', function () {
    heldSandbox('hog', 60);
    heldSandbox('hog', 60);
    heldSandbox('repeat', 60);
    heldSandbox('repeat', 60);

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldNotReceive('dropSandbox');

    // Two addresses share four seats, two each, and "repeat" already has two.
    expect((new DemoSeatReclaimer($pool))->reclaimFor('repeat'))->toBeNull();
});

test('expired sandboxes are not counted or taken', function () {
    foreach (range(1, 4) as $i) {
        heldSandbox('gone', 60, ['expires_at' => now()->subMinute()]);
    }

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldNotReceive('dropSandbox');

    expect((new DemoSeatReclaimer($pool))->reclaimFor('newcomer'))->toBeNull();
});

test('a sandbox another request retired first is skipped for the next one', function () {
    $first = heldSandbox('hog', 50);
    $second = heldSandbox('hog', 40);
    heldSandbox('hog', 30);
    heldSandbox('hog', 20);

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldReceive('dropSandbox')->once()->with($second->session_uuid);

    // A concurrent newcomer retires the oldest sandbox right after this one reads the list.
    DemoSession::retrieved(function (DemoSession $session) use ($first): void {
        if ($session->is($first)) {
            DemoSession::whereKey($first->id)->update(['expires_at' => now()->subSecond()]);
        }
    });

    expect((new DemoSeatReclaimer($pool))->reclaimFor('newcomer'))->toBe($second->session_uuid);
});

test('a sandbox whose drop fails does not count as a freed seat', function () {
    foreach (range(1, 4) as $i) {
        heldSandbox('hog', 60);
    }

    $pool = Mockery::mock(DemoDatabasePool::class);
    $pool->shouldReceive('dropSandbox')->andThrow(new RuntimeException('database busy'));

    expect((new DemoSeatReclaimer($pool))->reclaimFor('newcomer'))->toBeNull();
});
