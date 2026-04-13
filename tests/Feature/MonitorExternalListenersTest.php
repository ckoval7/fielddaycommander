<?php

use App\Models\AuditLog;
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

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);
});

test('command does nothing when no enabled settings exist', function () {
    $this->artisan('external-logger:monitor')
        ->assertExitCode(0);
});

test('command restarts crashed listeners', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => null,
    ]);

    $manager = Mockery::mock(ExternalLoggerManager::class);
    $manager->shouldReceive('getProcessStatus')
        ->with($this->config->id, 'n1mm')
        ->andReturn('crashed');
    $manager->shouldReceive('attemptRestart')
        ->with($this->config->id, 'n1mm')
        ->once()
        ->andReturn(true);

    $this->app->instance(ExternalLoggerManager::class, $manager);

    $this->artisan('external-logger:monitor')
        ->assertExitCode(0)
        ->expectsOutputToContain('Restarted crashed n1mm listener');
});

test('command does not restart running listeners', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 12345,
    ]);

    Cache::put("external-logger:n1mm:{$this->config->id}:heartbeat", [
        'pid' => 12345,
        'started_at' => now()->toIso8601String(),
        'last_heartbeat_at' => now()->toIso8601String(),
    ], 15);

    $manager = Mockery::mock(ExternalLoggerManager::class);
    $manager->shouldReceive('getProcessStatus')
        ->with($this->config->id, 'n1mm')
        ->andReturn('running');
    $manager->shouldNotReceive('attemptRestart');

    $this->app->instance(ExternalLoggerManager::class, $manager);

    $this->artisan('external-logger:monitor')
        ->assertExitCode(0);
});

test('command logs audit entry when restarting', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'wsjtx',
        'is_enabled' => true,
        'port' => 2237,
        'pid' => null,
    ]);

    $manager = Mockery::mock(ExternalLoggerManager::class);
    $manager->shouldReceive('getProcessStatus')
        ->with($this->config->id, 'wsjtx')
        ->andReturn('crashed');
    $manager->shouldReceive('attemptRestart')
        ->with($this->config->id, 'wsjtx')
        ->once()
        ->andReturn(true);

    $this->app->instance(ExternalLoggerManager::class, $manager);

    $this->artisan('external-logger:monitor')
        ->assertExitCode(0);

    $log = AuditLog::where('action', 'external_logger.auto_restarted')->first();
    expect($log)->not->toBeNull()
        ->and($log->new_values['listener_type'])->toBe('wsjtx')
        ->and($log->new_values['reason'])->toBe('crashed');
});

test('command handles multiple crashed listeners', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => null,
    ]);

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'wsjtx',
        'is_enabled' => true,
        'port' => 2237,
        'pid' => null,
    ]);

    $manager = Mockery::mock(ExternalLoggerManager::class);
    $manager->shouldReceive('getProcessStatus')
        ->with($this->config->id, 'n1mm')
        ->andReturn('crashed');
    $manager->shouldReceive('getProcessStatus')
        ->with($this->config->id, 'wsjtx')
        ->andReturn('crashed');
    $manager->shouldReceive('attemptRestart')
        ->with($this->config->id, 'n1mm')
        ->once()
        ->andReturn(true);
    $manager->shouldReceive('attemptRestart')
        ->with($this->config->id, 'wsjtx')
        ->once()
        ->andReturn(true);

    $this->app->instance(ExternalLoggerManager::class, $manager);

    $this->artisan('external-logger:monitor')
        ->assertExitCode(0)
        ->expectsOutputToContain('Restarted 2 crashed listener(s)');
});
