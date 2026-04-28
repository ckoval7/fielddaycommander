<?php

use App\Console\Commands\DemoSimulateActivity;
use App\Events\ContactLogged;
use App\Models\Contact;
use App\Models\OperatingSession;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
    Config::set('demo.simulator_cache_store', 'array');
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

test('simulator only logs contacts to sessions present at first run', function () {
    Event::fake([ContactLogged::class]);

    $this->seed(DemoSeeder::class);

    $command = new DemoSimulateActivity;
    $dbName = 'demo_'.str_repeat('a', 32);

    // First tick: snapshot the seeded active sessions into cache.
    $command->simulateForDatabase($dbName, 24);

    $snapshot = Cache::store('array')->get("demo:{$dbName}:simulated_session_ids");
    expect($snapshot)->toBeArray()->not->toBeEmpty();

    // Visitor opens their own logging session AFTER the snapshot.
    $visitorSession = OperatingSession::create([
        'station_id' => OperatingSession::query()->value('station_id'),
        'operator_user_id' => OperatingSession::query()->value('operator_user_id'),
        'band_id' => OperatingSession::query()->value('band_id'),
        'mode_id' => OperatingSession::query()->value('mode_id'),
        'start_time' => now(),
        'end_time' => null,
        'power_watts' => 100,
        'qso_count' => 0,
        'is_transcription' => false,
        'is_supervised' => false,
    ]);

    $contactsBefore = Contact::where('operating_session_id', $visitorSession->id)->count();

    // Run the simulator many times; the visitor's session must never receive a contact.
    for ($i = 0; $i < 50; $i++) {
        $command->simulateForDatabase($dbName, 24);
    }

    expect(Contact::where('operating_session_id', $visitorSession->id)->count())
        ->toBe($contactsBefore);
});

test('pre-registered seeded sessions take precedence over first-tick discovery', function () {
    Event::fake([ContactLogged::class]);

    $this->seed(DemoSeeder::class);

    $dbName = 'demo_'.str_repeat('b', 32);

    // Caller (DemoController) registers the seeded session IDs at provisioning.
    $seededIds = OperatingSession::active()->pluck('id')->all();
    DemoSimulateActivity::registerSeededSessions($dbName, $seededIds, 24);

    // Visitor opens their own session BEFORE the simulator's first tick.
    $visitorSession = OperatingSession::create([
        'station_id' => OperatingSession::query()->value('station_id'),
        'operator_user_id' => OperatingSession::query()->value('operator_user_id'),
        'band_id' => OperatingSession::query()->value('band_id'),
        'mode_id' => OperatingSession::query()->value('mode_id'),
        'start_time' => now(),
        'end_time' => null,
        'power_watts' => 100,
        'qso_count' => 0,
        'is_transcription' => false,
        'is_supervised' => false,
    ]);

    $command = new DemoSimulateActivity;
    for ($i = 0; $i < 50; $i++) {
        $command->simulateForDatabase($dbName, 24);
    }

    expect(Contact::where('operating_session_id', $visitorSession->id)->count())->toBe(0);
});

test('simulate-activity does not log contacts to expired sessions', function () {
    Event::fake([ContactLogged::class]);

    // Seed a demo-like state but set provisioned_at to 25 hours ago (expired)
    $this->seed(DemoSeeder::class);
    DB::table('system_config')->where('key', 'demo_provisioned_at')
        ->update(['value' => now()->subHours(25)->toIso8601String()]);

    // Simulate the command acting on the current (test) database
    // by calling the core logic with the current connection
    $this->artisan('demo:simulate-activity')
        ->assertSuccessful();

    Event::assertNotDispatched(ContactLogged::class);
});
