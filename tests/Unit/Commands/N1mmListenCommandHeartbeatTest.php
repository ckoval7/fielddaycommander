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

test('command clears pid when the port cannot be bound', function () {
    // Hold a UDP port without SO_REUSEADDR so the command's bind fails with
    // EADDRINUSE regardless of privileges. Relying on port 1 being privileged
    // breaks under root (e.g. in Docker): the bind succeeds and the command
    // enters its listen loop forever.
    $occupant = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($occupant, '0.0.0.0', 0);
    socket_getsockname($occupant, $address, $port);

    // Mirror the command's socket options to prove its bind will fail before
    // running it, so this test can never hang in the listen loop.
    $probe = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($probe, SOL_SOCKET, SO_REUSEADDR, 1);
    $probeBound = @socket_bind($probe, '0.0.0.0', $port);
    socket_close($probe);

    if ($probeBound) {
        socket_close($occupant);
        $this->fail("Port {$port} could be bound twice; refusing to start a listener that would never exit.");
    }

    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => $port,
    ]);

    try {
        $this->artisan('external-logger:n1mm')
            ->expectsOutputToContain("Failed to bind to port {$port}")
            ->assertExitCode(1);
    } finally {
        socket_close($occupant);
    }

    // The PID is written before binding and cleared once the bind fails.
    expect($setting->refresh()->pid)->toBeNull();
});
