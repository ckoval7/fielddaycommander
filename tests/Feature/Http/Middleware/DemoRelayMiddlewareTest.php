<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Http\Middleware\DemoRelayMiddleware;
use App\Models\DemoRelayRoute;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Relay;
use App\Services\RelayEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const SANDBOX_A = 'demo_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa';
const SANDBOX_B = 'demo_bbbbbbbb_bbbb_bbbb_bbbb_bbbbbbbbbbbb';

/**
 * Point the demo connection the way a worker would after serving a visitor.
 */
function workerOn(string $database): void
{
    Config::set('database.connections.demo.database', $database);
}

function demoConnectionDatabase(): string
{
    return config('database.connections.demo.database');
}

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);

    // Tests run on SQLite, which has no visitor schemas to look up.
    app()->instance(DemoRelayMiddleware::class, new class extends DemoRelayMiddleware
    {
        protected function databaseExists(string $database): bool
        {
            return in_array($database, [SANDBOX_A, SANDBOX_B], true);
        }
    });

    $this->config = EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]))
        ->create();
});

test('a code issued in a sandbox enrolls the relay into that sandbox from any worker', function () {
    workerOn(SANDBOX_A);
    $code = app(RelayEnrollmentService::class)->issue($this->config, 'Phone Tent')['code'];

    expect(DemoRelayRoute::sole()->database)->toBe(SANDBOX_A);

    workerOn(SANDBOX_B);
    $token = $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => strtolower($code)])
        ->assertCreated()
        ->json('token');

    expect(demoConnectionDatabase())->toBe(SANDBOX_A)
        ->and(DemoRelayRoute::databaseFor(Relay::hashSecret($token)))->toBe(SANDBOX_A);

    workerOn(SANDBOX_B);
    $this->withToken($token)->getJson('https://localhost/api/relay/v1/handshake')->assertOk();

    expect(demoConnectionDatabase())->toBe(SANDBOX_A);
});

test('an unknown token is refused without touching the worker\'s database', function () {
    workerOn(SANDBOX_B);

    $this->withToken('fdca_unknown')->getJson('https://localhost/api/relay/v1/handshake')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    expect(demoConnectionDatabase())->toBe(SANDBOX_B);
});

test('an unknown code is refused without touching the worker\'s database', function () {
    workerOn(SANDBOX_B);

    $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => 'ABCD-EFGH-JKMN'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_code');

    expect(demoConnectionDatabase())->toBe(SANDBOX_B);
});

test('a token whose sandbox has expired is refused', function () {
    DemoRelayRoute::remember(Relay::hashSecret('fdca_expired'), 'demo_cccccccc_cccc_cccc_cccc_cccccccccccc');
    workerOn(SANDBOX_B);

    $this->withToken('fdca_expired')->getJson('https://localhost/api/relay/v1/handshake')->assertUnauthorized();

    expect(demoConnectionDatabase())->toBe(SANDBOX_B);
});

test('codes issued outside a visitor sandbox are not routed', function () {
    workerOn('demo_base');
    app(RelayEnrollmentService::class)->issue($this->config, 'Phone Tent');

    expect(DemoRelayRoute::count())->toBe(0);
});

test('cleanup prunes routes older than the sandbox lifetime', function () {
    DB::shouldReceive('select')->once()->andReturn([]);

    DemoRelayRoute::remember('old-hash', SANDBOX_A);
    DemoRelayRoute::query()->update(['created_at' => now()->subHours(25)]);
    DemoRelayRoute::remember('new-hash', SANDBOX_A);

    $this->artisan('demo:cleanup')->assertSuccessful();

    expect(DemoRelayRoute::sole()->secret_hash)->toBe('new-hash');
});
