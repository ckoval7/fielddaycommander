<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\DemoDatabasePool;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.pool_size', 4);
});

test('fill pool tops the pool up to the configured size', function () {
    $this->mock(DemoDatabasePool::class)
        ->shouldReceive('fill')
        ->once()
        ->with(4)
        ->andReturn(['created' => 2, 'dropped' => 0, 'forgotten' => 0]);

    $this->artisan('demo:fill-pool')
        ->expectsOutputToContain('created 2, dropped 0')
        ->assertSuccessful();
});

test('fill pool reports entries whose database disappeared', function () {
    $this->mock(DemoDatabasePool::class)
        ->shouldReceive('fill')
        ->once()
        ->andReturn(['created' => 1, 'dropped' => 0, 'forgotten' => 1]);

    $this->artisan('demo:fill-pool')
        ->expectsOutputToContain('Forgot 1 pooled database')
        ->assertSuccessful();
});

test('fill pool exits early when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    $this->mock(DemoDatabasePool::class)->shouldNotReceive('fill');

    $this->artisan('demo:fill-pool')
        ->expectsOutputToContain('Demo mode is disabled')
        ->assertSuccessful();
});
