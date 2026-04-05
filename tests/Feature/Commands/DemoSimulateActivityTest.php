<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
});

test('simulate-activity command runs successfully when no demo databases exist', function () {
    $this->artisan('demo:simulate-activity')
        ->assertSuccessful();
});

test('simulate-activity is a no-op when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    $this->artisan('demo:simulate-activity')
        ->expectsOutputToContain('Demo mode is disabled')
        ->assertSuccessful();
});

test('simulate-activity does not log contacts to expired sessions', function () {
    Event::fake([\App\Events\ContactLogged::class]);

    // Seed a demo-like state but set provisioned_at to 25 hours ago (expired)
    $this->seed(\Database\Seeders\DemoSeeder::class);
    DB::table('system_config')->where('key', 'demo_provisioned_at')
        ->update(['value' => now()->subHours(25)->toIso8601String()]);

    // Simulate the command acting on the current (test) database
    // by calling the core logic with the current connection
    $this->artisan('demo:simulate-activity')
        ->assertSuccessful();

    Event::assertNotDispatched(\App\Events\ContactLogged::class);
});
