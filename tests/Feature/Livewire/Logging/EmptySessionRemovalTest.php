<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Console\Commands\PruneEmptySessionsCommand;
use App\Enums\StationUnavailableReason;
use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\StationSelect;
use App\Livewire\Stations\StationsList;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Services\BandModeSwitchService;
use App\Services\EmptySessionPruner;
use App\Support\StationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->twenty = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'allowed_fd' => true, 'sort_order' => 6]);
    $this->forty = Band::firstOrCreate(['name' => '40m'], ['meters' => 40, 'frequency_mhz' => 7.0, 'allowed_fd' => true, 'sort_order' => 5]);
    $this->twenty->update(['allowed_fd' => true]);
    $this->forty->update(['allowed_fd' => true]);

    $this->phone = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->cw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'HF 1', 'is_gota' => false]);

    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->twenty->id,
        'mode_id' => $this->phone->id,
        'power_watts' => 75,
        'start_time' => now()->subHour(),
        'qso_count' => 0,
    ]);

    $this->logContactOn = fn (OperatingSession $session): Contact => Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'band_id' => $session->band_id,
        'mode_id' => $session->mode_id,
        'qso_time' => now()->subMinutes(30),
    ]);
});

describe('band/mode switch', function () {
    test('changing band and then mode leaves no empty session in between', function () {
        ($this->logContactOn)($this->session);
        $this->actingAs($this->user);

        $middleId = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->forty->id, $this->phone->id, true)
            ->effects['returns'][0]['session_id'];

        $finalId = Livewire::test(LoggingInterface::class, ['operatingSession' => OperatingSession::find($middleId)])
            ->call('switchBandMode', $this->forty->id, $this->cw->id, true)
            ->effects['returns'][0]['session_id'];

        $final = OperatingSession::find($finalId);

        expect(OperatingSession::find($middleId))->toBeNull()
            ->and($this->session->fresh())->not->toBeNull()
            ->and($final->previous_session_id)->toBe($this->session->id)
            ->and($final->switchChainIds())->toBe([$this->session->id]);

        $earlier = Livewire::test(LoggingInterface::class, ['operatingSession' => $final])->instance()->earlierContacts;

        expect($earlier->pluck('operating_session_id')->all())->toBe([$this->session->id]);
    });

    test('the switch returns the new session linked past the removed one', function () {
        $session = app(BandModeSwitchService::class)->switchTo($this->session, $this->forty->id, $this->phone->id, true);

        expect(OperatingSession::find($this->session->id))->toBeNull()
            ->and($session->previous_session_id)->toBeNull()
            ->and($session->exists)->toBeTrue();
    });

    test('keeps a session whose contacts were all deleted', function () {
        ($this->logContactOn)($this->session)->delete();

        $session = app(BandModeSwitchService::class)->switchTo($this->session, $this->forty->id, $this->phone->id, true);

        expect($this->session->fresh())->not->toBeNull()
            ->and($session->previous_session_id)->toBe($this->session->id);
    });

    test('keeps an empty session while the browser still has contacts queued for it', function () {
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->forty->id, $this->phone->id, false);

        expect($this->session->fresh())->not->toBeNull();

        $this->postJson('/logging/contacts', [
            'uuid' => (string) Str::uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 75,
            'qso_time' => now()->subMinute()->toISOString(),
        ])->assertCreated();

        expect(Contact::sole()->operating_session_id)->toBe($this->session->id);
    });

    test('removes an empty GOTA session the same way', function () {
        $this->station->update(['is_gota' => true]);

        app(BandModeSwitchService::class)->switchTo($this->session->fresh(), $this->forty->id, $this->phone->id, true);

        expect(OperatingSession::find($this->session->id))->toBeNull();
    });
});

describe('end session', function () {
    test('deletes a session that logged nothing', function () {
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('endSession', true)
            ->assertRedirect(route('logging.station-select'));

        expect(OperatingSession::find($this->session->id))->toBeNull();
    });

    test('keeps a session with contacts', function () {
        ($this->logContactOn)($this->session);
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('endSession', true);

        expect($this->session->fresh()->end_time)->not->toBeNull();
    });

    test('keeps an empty session while contacts for it are still queued', function () {
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('endSession', false);

        expect($this->session->fresh()->end_time)->not->toBeNull();
    });
});

describe('after a session is removed', function () {
    test('opening its page goes to Station Select', function () {
        $this->actingAs($this->user);
        $id = $this->session->id;
        $this->session->update(['end_time' => now()]);
        app(EmptySessionPruner::class)->deleteIfEmpty($this->session);

        $this->get("/logging/session/{$id}")->assertRedirect(route('logging.station-select'));
    });

    test('a contact that still arrives for it fails with a clear message', function () {
        $this->actingAs($this->user);
        $id = $this->session->id;
        $this->session->update(['end_time' => now()]);
        app(EmptySessionPruner::class)->deleteIfEmpty($this->session);

        $this->postJson('/logging/contacts', [
            'uuid' => (string) Str::uuid(),
            'operating_session_id' => $id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 75,
            'qso_time' => now()->subMinute()->toISOString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['operating_session_id' => 'This operating session has ended.']);
    });
});

describe('pruner', function () {
    test('never removes an active, transcription or external logger session', function (array $attributes) {
        $this->session->update($attributes);

        expect(app(EmptySessionPruner::class)->deleteIfEmpty($this->session->fresh()))->toBeFalse()
            ->and($this->session->fresh())->not->toBeNull();
    })->with([
        'active' => [['end_time' => null]],
        'transcription' => [['end_time' => now(), 'is_transcription' => true]],
        'external logger' => [['end_time' => now(), 'external_source' => 'N1MM']],
    ]);

    test('re-points a later session to the removed one\'s predecessor', function () {
        $first = OperatingSession::factory()->create(['station_id' => $this->station->id, 'operator_user_id' => $this->user->id, 'end_time' => now()]);
        $this->session->update(['previous_session_id' => $first->id, 'end_time' => now()]);
        $next = OperatingSession::factory()->active()->create([
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'previous_session_id' => $this->session->id,
        ]);

        app(EmptySessionPruner::class)->deleteIfEmpty($this->session->fresh());

        expect($next->fresh()->previous_session_id)->toBe($first->id);
    });
});

describe('station mode sign-out', function () {
    beforeEach(function () {
        Setting::set('active_event_id', $this->event->id);
        $this->device = StationDevice::factory()->withToken('device-token')->create(['station_id' => $this->station->id]);
        $this->signOut = fn (array $form) => $this->actingAs($this->user)
            ->withSession([StationMode::SESSION_KEY => $this->device->id])
            ->withCookie(StationMode::COOKIE, 'device-token')
            ->post(route('station-mode.end'), $form)
            ->assertRedirect(route('station-mode.landing'));
    });

    test('removes the operator\'s session when it logged nothing and nothing is queued', function () {
        ($this->signOut)(['queue_checked' => '1', 'queued_sessions' => '']);

        expect(OperatingSession::find($this->session->id))->toBeNull();
    });

    test('keeps a session the browser still has contacts queued for', function () {
        ($this->signOut)(['queue_checked' => '1', 'queued_sessions' => (string) $this->session->id]);

        expect($this->session->fresh()->end_time)->not->toBeNull();
    });

    test('keeps the session when the browser could not report its queue', function () {
        ($this->signOut)([]);

        expect($this->session->fresh()->end_time)->not->toBeNull();
    });

    test('the sign-out button and the idle warning report the queue', function () {
        $this->withoutVite()
            ->actingAs($this->user)
            ->withSession([StationMode::SESSION_KEY => $this->device->id])
            ->withCookie(StationMode::COOKIE, 'device-token')
            ->get(route('logbook.index'))
            ->assertSee('@submit="globalThis.stampQueuedSessions?.($el)"', false)
            ->assertSee('globalThis.stampQueuedSessions?.(this.$refs.endSessionForm)', false);
    });
});

describe('sessions ended without word from their browser', function () {
    beforeEach(function () {
        Setting::set('active_event_id', $this->event->id);
        $this->prune = function (): void {
            $this->travel(PruneEmptySessionsCommand::DEFAULT_GRACE_MINUTES + 1)->minutes();
            $this->artisan('sessions:prune-empty')->assertSuccessful();
        };
        $this->expectRemovedAfterGrace = function (): void {
            expect($this->session->fresh()?->end_time)->not->toBeNull();

            ($this->prune)();

            expect(OperatingSession::find($this->session->id))->toBeNull();
        };
    });

    test('a takeover removes the empty session it ended once queued contacts have had time to sync', function () {
        $captain = User::factory()->create();
        $captain->givePermissionTo('log-contacts');
        $this->actingAs($captain);

        Livewire::test(StationSelect::class)
            ->set('takeoverStationId', $this->station->id)
            ->call('confirmTakeover');

        ($this->expectRemovedAfterGrace)();
    });

    test('End Sessions on the stations page removes an empty session after the wait', function () {
        Permission::firstOrCreate(['name' => 'view-stations']);
        Permission::firstOrCreate(['name' => 'manage-stations']);
        $captain = User::factory()->create();
        $captain->givePermissionTo(['view-stations', 'manage-stations']);
        $this->actingAs($captain);

        Livewire::test(StationsList::class)->call('endSessions', $this->station->id);

        ($this->expectRemovedAfterGrace)();
    });

    test('marking a station unavailable removes its empty session after the wait', function () {
        $this->station->markUnavailable(StationUnavailableReason::cases()[0], null, User::factory()->create());

        ($this->expectRemovedAfterGrace)();
    });

    test('a walk-up operator starting at another station removes their empty session there after the wait', function () {
        $other = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'HF 2', 'is_gota' => false]);
        $device = StationDevice::factory()->withToken('device-token')->create(['station_id' => $other->id]);
        $this->actingAs($this->user);
        session([StationMode::SESSION_KEY => $device->id]);

        Livewire::test(StationSelect::class)
            ->set('selectedStationId', $other->id)
            ->set('selectedBandId', $this->forty->id)
            ->set('selectedModeId', $this->cw->id)
            ->set('powerWatts', 100)
            ->call('startSession');

        ($this->expectRemovedAfterGrace)();
    });

    test('a revoked station browser removes the empty session it ended after the wait', function () {
        $device = StationDevice::factory()->withToken('device-token')->revoked()->create(['station_id' => $this->station->id]);

        $this->actingAs($this->user)
            ->withSession([StationMode::SESSION_KEY => $device->id])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        ($this->expectRemovedAfterGrace)();
    });

    test('opening a session after its event ended removes it after the wait', function () {
        $this->actingAs($this->user);
        $this->event->update(['end_time' => now()->subMinute()]);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertRedirect(route('logging.station-select'));

        ($this->expectRemovedAfterGrace)();
    });

    test('closing expired sessions removes the empty ones after the wait', function () {
        $this->event->update(['end_time' => now()->subMinute()]);

        $this->artisan('sessions:close-expired')->assertSuccessful();

        ($this->expectRemovedAfterGrace)();
    });

    test('keeps an empty session until the wait is over', function () {
        $this->session->update(['end_time' => now()]);
        $this->travel(PruneEmptySessionsCommand::DEFAULT_GRACE_MINUTES - 1)->minutes();

        $this->artisan('sessions:prune-empty')->assertSuccessful();

        expect($this->session->fresh())->not->toBeNull();
    });

    test('keeps ended sessions with contacts, deleted ones included', function () {
        ($this->logContactOn)($this->session)->delete();
        $this->session->update(['end_time' => now()]);

        ($this->prune)();

        expect($this->session->fresh())->not->toBeNull();
    });

    test('keeps active, transcription and external logger sessions', function (array $attributes) {
        $this->session->update($attributes);

        ($this->prune)();

        expect($this->session->fresh())->not->toBeNull();
    })->with([
        'active' => [['end_time' => null]],
        'transcription' => [['end_time' => now(), 'is_transcription' => true]],
        'external logger' => [['end_time' => now(), 'external_source' => 'N1MM']],
    ]);

    test('is on the schedule', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('sessions:prune-empty');
    });
});
