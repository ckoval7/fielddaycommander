<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
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
