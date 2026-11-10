<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ExternalLoggers\WireHarness;

/*
 * Windows has no SIGTERM, so ExternalLoggerManager::stopProcess() also leaves
 * a stop request in the cache. These run each real listener with a request
 * already waiting and check it exits and cleans up on its own.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    config(['services.external_loggers.bind_address' => '127.0.0.1']);

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);

    // If a listener ignores the request, fail instead of hanging the suite.
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static fn () => throw new RuntimeException('The listener ignored the stop request'));
    pcntl_alarm(15);
});

afterEach(function () {
    pcntl_alarm(0);
    pcntl_signal(SIGALRM, SIG_DFL);
});

test('a listener asked to stop exits and clears its heartbeat and pid', function (string $listenerType, string $protocol) {
    $port = $protocol === 'udp' ? WireHarness::freeUdpPort() : WireHarness::freeTcpPort();
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => $listenerType,
        'is_enabled' => true,
        'port' => $port,
        'pid' => getmypid(),
    ]);

    app(ExternalLoggerManager::class)->requestStop(getmypid());

    $exitCode = Artisan::call("external-logger:{$listenerType}", ['--event' => $this->config->id]);

    expect($exitCode)->toBe(0)
        ->and(Cache::has("external-logger:{$listenerType}:{$this->config->id}:heartbeat"))->toBeFalse()
        ->and($setting->refresh()->pid)->toBeNull()
        ->and(WireHarness::isListening($port, $protocol))->toBeFalse();
})->with([
    'N1MM' => ['n1mm', 'udp'],
    'WSJT-X' => ['wsjtx', 'udp'],
    'N3FJP' => ['n3fjp', 'tcp'],
]);
