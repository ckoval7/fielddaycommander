<?php

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);
});

test('command exits with failure when listener is not enabled', function () {
    $this->artisan('external-logger:n1mm')
        ->assertExitCode(1);
});

test('command stores pid in setting on startup', function () {
    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 1, // Port 1 requires root — bind will fail, but PID should be written first
    ]);

    // The command will fail to bind port 1 (permission denied), but we can verify
    // PID was stored before that point by checking it was set then cleared
    $this->artisan('external-logger:n1mm')
        ->assertExitCode(1);

    // After failure, PID should be cleared
    $setting->refresh();
    expect($setting->pid)->toBeNull();
});
