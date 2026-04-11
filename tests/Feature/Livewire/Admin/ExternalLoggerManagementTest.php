<?php

use App\Livewire\Admin\ExternalLoggerManagement;
use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Permission::create(['name' => 'import-contacts']);

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('import-contacts');
    $this->actingAs($this->user);
});

test('renders with correct process status when stopped', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Stopped');
});

test('renders with running status when heartbeat exists', function () {
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
        'packets_received' => 50,
        'packets_processed' => 48,
        'errors' => 2,
        'last_packet_at' => now()->subSeconds(3)->toIso8601String(),
        'port' => 12060,
    ], 15);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Listening on port 12060')
        ->assertSee('50 packets')
        ->assertSee('2 errors');
});

test('toggleN1mm enables and starts process', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->call('toggleN1mm');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeTrue()
        ->and($setting->pid)->toBeGreaterThan(0);

    // Clean up spawned process
    if ($setting->pid && posix_kill($setting->pid, 0)) {
        posix_kill($setting->pid, SIGTERM);
    }
});

test('toggleN1mm disables and stops process', function () {
    // Start a dummy process to have a PID to kill
    $process = new Process(['sleep', '60']);
    $process->start();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => $process->getPid(),
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('n1mmEnabled', true)
        ->call('toggleN1mm');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull();
});

test('displays firewall reminder in setup instructions', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('sudo ufw allow');
});

test('renders WSJTX section with stopped status', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('WSJTX / JTDX')
        ->assertSee('Stopped');
});

test('renders WSJTX with running status when heartbeat exists', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'wsjtx',
        'is_enabled' => true,
        'port' => 2237,
        'pid' => 12345,
    ]);

    Cache::put("external-logger:wsjtx:{$this->config->id}:heartbeat", [
        'pid' => 12345,
        'started_at' => now()->toIso8601String(),
        'last_heartbeat_at' => now()->toIso8601String(),
        'packets_received' => 25,
        'packets_processed' => 24,
        'errors' => 1,
        'last_packet_at' => now()->subSeconds(3)->toIso8601String(),
        'port' => 2237,
    ], 15);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Listening on port 2237')
        ->assertSee('25 packets')
        ->assertSee('1 errors');
});

test('toggleWsjtx enables and starts process', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->call('toggleWsjtx');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'wsjtx')
        ->first();

    expect($setting->is_enabled)->toBeTrue()
        ->and($setting->pid)->toBeGreaterThan(0);

    // Clean up spawned process
    if ($setting->pid && posix_kill($setting->pid, 0)) {
        posix_kill($setting->pid, SIGTERM);
    }
});

test('toggleWsjtx disables and stops process', function () {
    // Start a dummy process to have a PID to kill
    $process = new Process(['sleep', '60']);
    $process->start();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'wsjtx',
        'is_enabled' => true,
        'port' => 2237,
        'pid' => $process->getPid(),
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('wsjtxEnabled', true)
        ->call('toggleWsjtx');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'wsjtx')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull();
});

test('displays WSJTX setup instructions', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('File > Settings > Reporting > UDP Server');
});

test('renders UDP ADIF section with stopped status', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('UDP ADIF (fldigi, etc.)')
        ->assertSee('Stopped');
});

test('renders UDP ADIF with running status when heartbeat exists', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'udp-adif',
        'is_enabled' => true,
        'port' => 2238,
        'pid' => 12345,
    ]);

    Cache::put("external-logger:udp-adif:{$this->config->id}:heartbeat", [
        'pid' => 12345,
        'started_at' => now()->toIso8601String(),
        'last_heartbeat_at' => now()->toIso8601String(),
        'packets_received' => 30,
        'packets_processed' => 28,
        'errors' => 2,
        'last_packet_at' => now()->subSeconds(3)->toIso8601String(),
        'port' => 2238,
    ], 15);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Listening on port 2238')
        ->assertSee('30 packets')
        ->assertSee('2 errors');
});

test('toggleUdpAdif enables and starts process', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->call('toggleUdpAdif');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'udp-adif')
        ->first();

    expect($setting->is_enabled)->toBeTrue()
        ->and($setting->pid)->toBeGreaterThan(0);

    // Clean up spawned process
    if ($setting->pid && posix_kill($setting->pid, 0)) {
        posix_kill($setting->pid, SIGTERM);
    }
});

test('toggleUdpAdif disables and stops process', function () {
    // Start a dummy process to have a PID to kill
    $process = new Process(['sleep', '60']);
    $process->start();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'udp-adif',
        'is_enabled' => true,
        'port' => 2238,
        'pid' => $process->getPid(),
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('udpAdifEnabled', true)
        ->call('toggleUdpAdif');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'udp-adif')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull();
});

test('displays UDP ADIF setup instructions', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Configure > Config Dialog > Misc > ADIF');
});
