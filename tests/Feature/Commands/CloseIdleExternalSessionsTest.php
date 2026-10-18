<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\OperatingSession;

beforeEach(function () {
    $this->freezeSecond();
});

test('closes external sessions whose last activity is older than the timeout', function () {
    $idle = OperatingSession::factory()->external()->create([
        'last_activity_at' => now()->subMinutes(31),
    ]);

    $this->artisan('external-logger:close-idle')
        ->expectsOutput('Closed 1 idle external session(s).')
        ->assertSuccessful();

    expect($idle->refresh()->end_time?->equalTo(now()))->toBeTrue();
});

test('leaves active external sessions open', function () {
    $active = OperatingSession::factory()->external()->create([
        'last_activity_at' => now()->subMinutes(29),
        'created_at' => now()->subHours(5),
    ]);

    $this->artisan('external-logger:close-idle')
        ->doesntExpectOutputToContain('Closed')
        ->assertSuccessful();

    expect($active->refresh()->end_time)->toBeNull();
});

test('falls back to created_at when last_activity_at is null', function () {
    $staleNeverActive = OperatingSession::factory()->external()->create([
        'last_activity_at' => null,
        'created_at' => now()->subMinutes(45),
    ]);
    $freshNeverActive = OperatingSession::factory()->external()->create([
        'last_activity_at' => null,
        'created_at' => now()->subMinutes(10),
    ]);

    $this->artisan('external-logger:close-idle')
        ->expectsOutput('Closed 1 idle external session(s).')
        ->assertSuccessful();

    expect($staleNeverActive->refresh()->end_time)->not->toBeNull()
        ->and($freshNeverActive->refresh()->end_time)->toBeNull();
});

test('ignores non-external sessions even when idle', function () {
    $manual = OperatingSession::factory()->create([
        'last_activity_at' => now()->subHours(3),
        'created_at' => now()->subHours(3),
    ]);

    $this->artisan('external-logger:close-idle')->assertSuccessful();

    expect($manual->refresh()->end_time)->toBeNull();
});

test('does not touch external sessions that are already ended', function () {
    $endedAt = now()->subHours(2);
    $ended = OperatingSession::factory()->external()->create([
        'last_activity_at' => now()->subHours(3),
        'end_time' => $endedAt,
    ]);

    $this->artisan('external-logger:close-idle')->assertSuccessful();

    expect($ended->refresh()->end_time->equalTo($endedAt))->toBeTrue();
});

test('respects a custom minutes option', function () {
    $session = OperatingSession::factory()->external()->create([
        'last_activity_at' => now()->subMinutes(10),
    ]);

    $this->artisan('external-logger:close-idle', ['--minutes' => 15])->assertSuccessful();
    expect($session->refresh()->end_time)->toBeNull();

    $this->artisan('external-logger:close-idle', ['--minutes' => 5])
        ->expectsOutput('Closed 1 idle external session(s).')
        ->assertSuccessful();
    expect($session->refresh()->end_time)->not->toBeNull();
});
