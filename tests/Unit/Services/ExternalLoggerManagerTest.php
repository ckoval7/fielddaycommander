<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use App\Services\ListenerProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create();

    // Fake the OS process layer so no real processes are spawned or signalled.
    $this->processRunner = new class extends ListenerProcessRunner
    {
        /** @var list<string> */
        public array $spawnedCommands = [];

        /** @var list<int> */
        public array $terminatedPids = [];

        /** @var list<int> */
        public array $alivePids = [];

        /** @var array<int, string> */
        public array $commandLines = [];

        public ?int $nextPid = 424242;

        public function spawnDetached(string $command): ?int
        {
            $this->spawnedCommands[] = $command;

            return $this->nextPid;
        }

        public function isAlive(int $pid): bool
        {
            return in_array($pid, $this->alivePids, true);
        }

        public function terminate(int $pid): void
        {
            $this->terminatedPids[] = $pid;
            $this->alivePids = array_values(array_diff($this->alivePids, [$pid]));
        }

        public function commandLine(int $pid): ?string
        {
            return $this->commandLines[$pid] ?? null;
        }
    };

    $this->manager = new ExternalLoggerManager($this->processRunner);
});

test('creates setting when enabling for first time', function () {
    $this->manager->enable($this->config->id, 'n1mm', 12060);

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->is_enabled)->toBeTrue()
        ->and($setting->port)->toBe(12060);
});

test('updates existing setting when enabling', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    $this->manager->enable($this->config->id, 'n1mm', 12070);

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeTrue()
        ->and($setting->port)->toBe(12070);
});

test('disables listener', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    $this->manager->disable($this->config->id, 'n1mm');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeFalse();
});

test('checks if listener is enabled', function () {
    expect($this->manager->isEnabled($this->config->id, 'n1mm'))->toBeFalse();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    expect($this->manager->isEnabled($this->config->id, 'n1mm'))->toBeTrue();
});

test('gets setting for listener', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12080,
    ]);

    $setting = $this->manager->getSetting($this->config->id, 'n1mm');

    expect($setting)->not->toBeNull()
        ->and($setting->port)->toBe(12080);
});

test('returns null setting when none exists', function () {
    expect($this->manager->getSetting($this->config->id, 'n1mm'))->toBeNull();
});

test('gets all enabled settings across events', function () {
    $config2 = EventConfiguration::factory()->create();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);
    ExternalLoggerSetting::create([
        'event_configuration_id' => $config2->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    $enabled = $this->manager->getEnabledSettings('n1mm');

    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()->event_configuration_id)->toBe($this->config->id);
});

test('startProcess spawns detached listener command and stores pid', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    $pid = $this->manager->startProcess($this->config->id, 'n1mm');

    expect($pid)->toBe(424242)
        ->and($setting->refresh()->pid)->toBe(424242)
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1)
        ->and($this->processRunner->spawnedCommands[0])
        ->toContain("external-logger:n1mm --event={$this->config->id}")
        ->toContain('artisan')
        ->toEndWith('& echo $!');
});

test('startProcess returns null and leaves pid unset when spawn reports no pid', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);
    $this->processRunner->nextPid = null;

    expect($this->manager->startProcess($this->config->id, 'n1mm'))->toBeNull()
        ->and($setting->refresh()->pid)->toBeNull();
});

test('startProcess returns null without spawning when no setting exists', function () {
    expect($this->manager->startProcess($this->config->id, 'n1mm'))->toBeNull()
        ->and($this->processRunner->spawnedCommands)->toBeEmpty();
});

test('stopProcess sends SIGTERM and clears pid', function () {
    $this->processRunner->alivePids = [31337];

    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 31337,
    ]);

    $this->manager->stopProcess($this->config->id, 'n1mm');

    expect($setting->refresh()->pid)->toBeNull()
        ->and($this->processRunner->terminatedPids)->toBe([31337]);
});

test('stopProcess clears pid without signalling a dead process', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 31337,
    ]);

    $this->manager->stopProcess($this->config->id, 'n1mm');

    expect($setting->refresh()->pid)->toBeNull()
        ->and($this->processRunner->terminatedPids)->toBeEmpty();
});

test('getProcessStatus returns stopped when disabled', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('stopped');
});

test('getProcessStatus returns running when heartbeat exists', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 99999,
    ]);

    Cache::put("external-logger:n1mm:{$this->config->id}:heartbeat", [
        'pid' => 99999,
        'started_at' => now()->toIso8601String(),
        'last_heartbeat_at' => now()->toIso8601String(),
        'packets_received' => 10,
        'packets_processed' => 9,
        'errors' => 1,
        'last_packet_at' => now()->toIso8601String(),
        'port' => 12060,
    ], 15);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('running');
});

test('getProcessStatus returns crashed when enabled with dead pid and no heartbeat', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 99999,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('crashed');
});

test('getProcessStatus returns starting when enabled with alive listener pid but no heartbeat', function () {
    $this->processRunner->commandLines[31337] = "php\0artisan\0external-logger:n1mm\0--event={$this->config->id}\0";

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 31337,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('starting');
});

test('getProcessStatus returns crashed when pid reused by unrelated process', function () {
    $this->processRunner->commandLines[31337] = "/usr/sbin/sshd\0-D\0";

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 31337,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('crashed');
});

test('getProcessStatus returns crashed when enabled with no pid to trigger auto-restart', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => null,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('crashed');
});

test('getHeartbeat returns cached stats', function () {
    $heartbeat = [
        'pid' => 12345,
        'started_at' => now()->toIso8601String(),
        'last_heartbeat_at' => now()->toIso8601String(),
        'packets_received' => 847,
        'packets_processed' => 832,
        'errors' => 15,
        'last_packet_at' => now()->subSeconds(3)->toIso8601String(),
        'port' => 12060,
    ];

    Cache::put("external-logger:n1mm:{$this->config->id}:heartbeat", $heartbeat, 15);

    $result = $this->manager->getHeartbeat($this->config->id, 'n1mm');

    expect($result)->toBe($heartbeat);
});

test('getHeartbeat returns null when no heartbeat cached', function () {
    $result = $this->manager->getHeartbeat($this->config->id, 'n1mm');

    expect($result)->toBeNull();
});

test('attemptRestart stops old process, starts a new one and sets cooldown', function () {
    $this->processRunner->alivePids = [99999];

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 99999,
    ]);

    $result = $this->manager->attemptRestart($this->config->id, 'n1mm');

    expect($result)->toBeTrue();

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->pid)->toBe(424242)
        ->and($this->processRunner->terminatedPids)->toBe([99999])
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1)
        ->and(Cache::has("external-logger:n1mm:{$this->config->id}:restart-cooldown"))->toBeTrue();
});

test('attemptRestart returns false without cooldown when spawn fails', function () {
    $this->processRunner->nextPid = null;

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    expect($this->manager->attemptRestart($this->config->id, 'n1mm'))->toBeFalse()
        ->and(Cache::has("external-logger:n1mm:{$this->config->id}:restart-cooldown"))->toBeFalse();
});

test('attemptRestart returns false during cooldown', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 99999,
    ]);

    Cache::put("external-logger:n1mm:{$this->config->id}:restart-cooldown", true, 30);

    $result = $this->manager->attemptRestart($this->config->id, 'n1mm');

    expect($result)->toBeFalse()
        ->and($this->processRunner->spawnedCommands)->toBeEmpty()
        ->and($this->processRunner->terminatedPids)->toBeEmpty();
});

test('a listener that failed to start is not respawned until its backoff passes', function () {
    $this->manager->enable($this->config->id, 'n1mm', 12060);
    $this->manager->recordStartupFailure($this->config->id, 'n1mm', 'The PHP sockets extension is not installed.');

    expect($this->manager->attemptRestart($this->config->id, 'n1mm'))->toBeFalse()
        ->and($this->processRunner->spawnedCommands)->toBe([]);

    $this->travel(61)->seconds();

    expect($this->manager->attemptRestart($this->config->id, 'n1mm'))->toBeTrue()
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1);
});

test('the startup backoff doubles with each failure, up to an hour', function () {
    $this->manager->enable($this->config->id, 'n1mm', 12060);

    foreach (range(1, 10) as $failure) {
        $this->manager->recordStartupFailure($this->config->id, 'n1mm', 'bind failed');
    }

    $this->travel(59)->minutes();
    expect($this->manager->isBackingOff($this->config->id, 'n1mm'))->toBeTrue();

    $this->travel(2)->minutes();
    expect($this->manager->isBackingOff($this->config->id, 'n1mm'))->toBeFalse();
});

test('a repeated startup failure is logged once per reason', function () {
    Log::spy();

    foreach (range(1, 5) as $failure) {
        $this->manager->recordStartupFailure($this->config->id, 'n1mm', 'The PHP sockets extension is not installed.');
    }

    Log::shouldHaveReceived('error')->once();
});

test('re-enabling a listener clears its startup backoff', function () {
    $this->manager->recordStartupFailure($this->config->id, 'n1mm', 'bind failed');

    $this->manager->enable($this->config->id, 'n1mm', 12061);

    expect($this->manager->isBackingOff($this->config->id, 'n1mm'))->toBeFalse();
});
