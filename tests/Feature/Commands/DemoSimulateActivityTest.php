<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Console\Commands\DemoSimulateActivity;
use App\Events\ContactLogged;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
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

test('shift attendance does nothing without registered assignments', function () {
    $this->seed(DemoSeeder::class);

    $command = new DemoSimulateActivity;
    $statuses = ShiftAssignment::pluck('status', 'id');

    $this->travelTo(now()->addHours(30));

    expect($command->simulateShiftAttendance('demo_'.str_repeat('c', 32)))
        ->toBe(['checked_in' => 0, 'checked_out' => 0]);
    expect(ShiftAssignment::pluck('status', 'id'))->toEqual($statuses);
});

test('shift attendance checks volunteers in once their shift is underway', function () {
    $this->seed(DemoSeeder::class);

    $dbName = 'demo_'.str_repeat('d', 32);
    $upcoming = Shift::where('start_time', '>', now())->orderBy('start_time')->first();
    $assignments = $upcoming->assignments()->get();

    DemoSimulateActivity::registerSeededShiftAssignments($dbName, $assignments->pluck('id')->all(), 24);

    // Before the arrival window opens, nobody shows up.
    $this->travelTo($upcoming->start_time->copy()->subMinutes(30));
    (new DemoSimulateActivity)->simulateShiftAttendance($dbName);
    expect($upcoming->assignments()->where('status', ShiftAssignment::STATUS_SCHEDULED)->count())
        ->toBe($assignments->count());

    // Ten minutes past the start, anyone still missing is checked in.
    $this->travelTo($upcoming->start_time->copy()->addMinutes(10));
    (new DemoSimulateActivity)->simulateShiftAttendance($dbName);

    $assignments->each(function (ShiftAssignment $assignment) {
        $assignment->refresh();
        expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
        expect($assignment->checked_in_at)->not->toBeNull();
    });
});

test('shift attendance checks volunteers out after their shift ends', function () {
    $this->seed(DemoSeeder::class);

    $dbName = 'demo_'.str_repeat('e', 32);
    $current = ShiftAssignment::where('status', ShiftAssignment::STATUS_CHECKED_IN)->with('shift')->first();

    DemoSimulateActivity::registerSeededShiftAssignments($dbName, [$current->id], 24);

    $this->travelTo($current->shift->end_time->copy()->addMinutes(10));
    (new DemoSimulateActivity)->simulateShiftAttendance($dbName);

    $current->refresh();
    expect($current->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
    expect($current->checked_out_at)->not->toBeNull();
});

/**
 * Register the seeded stations, sessions and non-persona shift assignments
 * the way the provisioner does.
 */
function registerSeededDemo(string $dbName): void
{
    $stationIds = Station::where('is_gota', false)->where('is_vhf_only', false)->pluck('id')->all();
    DemoSimulateActivity::registerSeededStations($dbName, $stationIds, 24);
    DemoSimulateActivity::registerSeededSessions($dbName, OperatingSession::active()->whereIn('station_id', $stationIds)->pluck('id')->all(), 24);
    DemoSimulateActivity::registerSeededShiftAssignments($dbName, ShiftAssignment::pluck('id')->all(), 24);
}

test('operator rotation leaves on-duty operators at their stations', function () {
    $this->seed(DemoSeeder::class);
    $dbName = 'demo_'.str_repeat('f', 32);
    registerSeededDemo($dbName);

    $before = OperatingSession::active()->pluck('operator_user_id', 'station_id');

    $counts = (new DemoSimulateActivity)->rotateOperators($dbName, 24);

    expect($counts['ended'])->toBe(0);
    expect(OperatingSession::active()->pluck('operator_user_id', 'station_id')->only($before->keys()->all()))->toEqual($before);
});

test('operator rotation hands stations to the incoming crew', function () {
    $this->seed(DemoSeeder::class);
    $dbName = 'demo_'.str_repeat('1', 32);
    registerSeededDemo($dbName);

    $outgoing = OperatingSession::active()->pluck('operator_user_id');
    $nextShift = Shift::whereHas('shiftRole', fn ($q) => $q->where('name', 'Operator'))
        ->where('start_time', '>', now())
        ->orderBy('start_time')
        ->firstOrFail();

    // Outgoing crew has checked out and the incoming crew has arrived.
    $this->travelTo($nextShift->start_time->copy()->addMinutes(5));
    ShiftAssignment::whereIn('user_id', $outgoing)->where('status', ShiftAssignment::STATUS_CHECKED_IN)->get()->each->checkOut();
    $nextShift->assignments()->each(fn (ShiftAssignment $assignment) => $assignment->checkIn());

    $counts = (new DemoSimulateActivity)->rotateOperators($dbName, 24);

    expect($counts['ended'])->toBe($outgoing->count());
    expect($counts['started'])->toBeGreaterThan(0);

    $incoming = $nextShift->assignments()->pluck('user_id');
    OperatingSession::active()->get()->each(function (OperatingSession $session) use ($incoming, $outgoing) {
        expect($incoming)->toContain($session->operator_user_id);
        expect($outgoing)->not->toContain($session->operator_user_id);
    });

    // New sessions are adopted by the contact simulator.
    $simulated = Cache::store('array')->get("demo:{$dbName}:simulated_session_ids");
    OperatingSession::active()->pluck('id')->each(fn (int $id) => expect($simulated)->toContain($id));
});

test('operator rotation never takes over a session the visitor started', function () {
    $this->seed(DemoSeeder::class);
    $dbName = 'demo_'.str_repeat('2', 32);
    registerSeededDemo($dbName);

    $idleStation = Station::where('is_gota', false)->where('is_vhf_only', false)
        ->whereDoesntHave('operatingSessions', fn ($q) => $q->whereNull('end_time'))
        ->firstOrFail();
    $template = OperatingSession::where('station_id', $idleStation->id)->latest('start_time')->firstOrFail();

    $visitorSession = OperatingSession::create([
        'station_id' => $idleStation->id,
        'operator_user_id' => User::role('Operator')->orderBy('id')->firstOrFail()->id,
        'band_id' => $template->band_id,
        'mode_id' => $template->mode_id,
        'start_time' => now(),
        'end_time' => null,
        'power_watts' => 100,
        'qso_count' => 0,
        'is_transcription' => false,
        'is_supervised' => false,
    ]);

    $this->travelTo(now()->addHours(30));
    (new DemoSimulateActivity)->rotateOperators($dbName, 24);

    expect($visitorSession->fresh()->end_time)->toBeNull();
});

test('operator rotation is a no-op for demos provisioned without stations', function () {
    $this->seed(DemoSeeder::class);

    expect((new DemoSimulateActivity)->rotateOperators('demo_'.str_repeat('3', 32), 24))
        ->toBe(['ended' => 0, 'started' => 0]);
});
