<?php

use App\Livewire\Admin\ExternalLoggerManagement;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\ExternalLoggerSetting;
use App\Models\User;
use App\Scoring\Rules\WinterFieldDay2027;
use App\Services\ListenerProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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

    // Fake the OS process layer so toggling never spawns real listeners or binds ports.
    $this->processRunner = new class extends ListenerProcessRunner
    {
        /** @var list<string> */
        public array $spawnedCommands = [];

        /** @var list<int> */
        public array $terminatedPids = [];

        public function spawnDetached(string $command): ?int
        {
            $this->spawnedCommands[] = $command;

            return 424242;
        }

        public function isAlive(int $pid): bool
        {
            return true;
        }

        public function terminate(int $pid): void
        {
            $this->terminatedPids[] = $pid;
        }

        public function commandLine(int $pid): ?string
        {
            return null;
        }
    };
    $this->app->instance(ListenerProcessRunner::class, $this->processRunner);
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
        ->and($setting->pid)->toBe(424242)
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1)
        ->and($this->processRunner->spawnedCommands[0])->toContain("external-logger:{$setting->listener_type} ");
});

test('toggleN1mm disables and stops process', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
        'pid' => 31337,
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('n1mmEnabled', true)
        ->call('toggleN1mm');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull()
        ->and($this->processRunner->terminatedPids)->toBe([31337]);
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
        ->and($setting->pid)->toBe(424242)
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1)
        ->and($this->processRunner->spawnedCommands[0])->toContain("external-logger:{$setting->listener_type} ");
});

test('toggleWsjtx disables and stops process', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'wsjtx',
        'is_enabled' => true,
        'port' => 2237,
        'pid' => 31337,
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('wsjtxEnabled', true)
        ->call('toggleWsjtx');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'wsjtx')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull()
        ->and($this->processRunner->terminatedPids)->toBe([31337]);
});

test('displays WSJTX setup instructions', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('File > Settings > Reporting > UDP Server');
});

test('WSJTX setup instructions do not point to multicast outgoing interfaces', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertDontSee('Outgoing Interfaces')
        ->assertSee('not a multicast address')
        ->assertSee('Op Call')
        ->assertSee('Special operating activity');
});

test('WSJTX and UDP ADIF setup instructions explain station callsign matching', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSeeInOrder(['WSJTX / JTDX', 'station callsign', 'UDP ADIF (fldigi, etc.)', 'station callsign'])
        ->assertSee('receiving station')
        ->assertDontSee('each station');
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
        ->and($setting->pid)->toBe(424242)
        ->and($this->processRunner->spawnedCommands)->toHaveCount(1)
        ->and($this->processRunner->spawnedCommands[0])->toContain("external-logger:{$setting->listener_type} ");
});

test('toggleUdpAdif disables and stops process', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'udp-adif',
        'is_enabled' => true,
        'port' => 2238,
        'pid' => 31337,
    ]);

    Livewire::test(ExternalLoggerManagement::class)
        ->set('udpAdifEnabled', true)
        ->call('toggleUdpAdif');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'udp-adif')
        ->first();

    expect($setting->is_enabled)->toBeFalse()
        ->and($setting->pid)->toBeNull()
        ->and($this->processRunner->terminatedPids)->toBe([31337]);
});

test('displays UDP ADIF setup instructions', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Configure > Config Dialog > Logging > Cloud-UDP');
});

test('shows last log received section with no-contact placeholder when listener is running but no log yet', function () {
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
        'packets_received' => 0,
        'packets_processed' => 0,
        'errors' => 0,
        'last_packet_at' => null,
        'port' => 12060,
    ], 15);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Last Log Received')
        ->assertSee('No contacts received yet');
});

test('shows accepted badge and callsign when last log was accepted', function () {
    Cache::put("external-logger:n1mm:{$this->config->id}:last-log", [
        'callsign' => 'W1AW',
        'band' => '20m',
        'mode' => 'SSB',
        'qso_time' => now()->toIso8601String(),
        'section' => 'CT',
        'source' => 'n1mm',
        'received_at' => now()->toIso8601String(),
        'accepted' => true,
        'rejection_reason' => null,
    ], 60 * 60 * 24);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Last Log Received')
        ->assertSee('Accepted')
        ->assertSee('W1AW');
});

test('shows rejection reason badge and callsign when last log was out of period', function () {
    Cache::put("external-logger:n1mm:{$this->config->id}:last-log", [
        'callsign' => 'K1XYZ',
        'band' => '40m',
        'mode' => 'CW',
        'qso_time' => now()->toIso8601String(),
        'section' => null,
        'source' => 'n1mm',
        'received_at' => now()->toIso8601String(),
        'accepted' => false,
        'rejection_reason' => 'outside event window',
    ], 60 * 60 * 24);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee('Last Log Received')
        ->assertSee('outside event window')
        ->assertSee('K1XYZ');
});

test('warns that WSJT-X contacts earn no credit under WFD 2027', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $this->config->event->update(['event_type_id' => $wfd->id, 'rules_version' => '2027']);

    Livewire::test(ExternalLoggerManagement::class)
        ->assertSee(WinterFieldDay2027::NO_CREDIT_WSJT)
        ->assertSee('Contacts in those modes (FT8, FT4 and the like) are still logged');
});

test('does not warn about WSJT-X credit for rulesets that allow it', function () {
    Livewire::test(ExternalLoggerManagement::class)
        ->assertDontSee('Contacts in those modes (FT8, FT4 and the like) are still logged');
});
