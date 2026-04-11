<?php

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create();
    $this->manager = new ExternalLoggerManager;
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

test('startProcess spawns background process and stores pid', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    $this->manager->startProcess($this->config->id, 'n1mm');

    $setting->refresh();
    expect($setting->pid)->toBeInt()
        ->and($setting->pid)->toBeGreaterThan(0);

    // Clean up: kill the spawned process
    if ($setting->pid && posix_kill($setting->pid, 0)) {
        posix_kill($setting->pid, SIGTERM);
    }
});

test('stopProcess sends SIGTERM and clears pid', function () {
    // Start a dummy sleep process to get a real PID
    $output = [];
    exec('sleep 60 & echo $!', $output);
    $dummyPid = (int) $output[0];

    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => $dummyPid,
    ]);

    $this->manager->stopProcess($this->config->id, 'n1mm');

    $setting->refresh();
    expect($setting->pid)->toBeNull();

    // Give process time to terminate
    usleep(50000);

    // Verify the process was killed
    expect(posix_kill($dummyPid, 0))->toBeFalse();
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
    // Spawn a process whose /proc/pid/cmdline contains the listener signature
    $output = [];
    exec("bash -c 'sleep 60; true' external-logger:n1mm > /dev/null 2>&1 & echo \$!", $output);
    $listenerPid = (int) $output[0];

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => $listenerPid,
    ]);

    $status = $this->manager->getProcessStatus($this->config->id, 'n1mm');

    expect($status)->toBe('starting');

    // Clean up
    posix_kill($listenerPid, SIGTERM);
});

test('getProcessStatus returns crashed when pid reused by unrelated process', function () {
    // Current PHP process is alive but its cmdline is not our listener
    $unrelatedPid = getmypid();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => $unrelatedPid,
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

test('attemptRestart starts process and returns true when no cooldown', function () {
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

    // Should have a new PID
    expect($setting->pid)->not->toBe(99999)
        ->and($setting->pid)->toBeGreaterThan(0);

    // Cooldown should be set
    expect(Cache::has("external-logger:n1mm:{$this->config->id}:restart-cooldown"))->toBeTrue();

    // Clean up spawned process
    if ($setting->pid && posix_kill($setting->pid, 0)) {
        posix_kill($setting->pid, SIGTERM);
    }
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

    expect($result)->toBeFalse();
});
