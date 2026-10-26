<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logging\LoggingInterface;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\BandModeSwitchService;
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
        'qso_count' => 0,
    ]);
});

describe('band/mode switch', function () {
    test('ends the session and starts one on the new band with the same station and power', function () {
        $this->actingAs($this->user);

        $result = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->forty->id, $this->phone->id)
            ->effects['returns'][0];

        $newSession = OperatingSession::latest('id')->first();

        expect($result)->toBe([
            'ok' => true,
            'session_id' => $newSession->id,
            'url' => route('logging.session', $newSession),
        ])
            ->and($this->session->fresh()->end_time)->not->toBeNull()
            ->and($newSession)
            ->station_id->toBe($this->station->id)
            ->operator_user_id->toBe($this->user->id)
            ->band_id->toBe($this->forty->id)
            ->mode_id->toBe($this->phone->id)
            ->power_watts->toBe(75)
            ->end_time->toBeNull();
    });

    test('keeps the session when the band and mode are unchanged', function () {
        $session = app(BandModeSwitchService::class)->switchTo($this->session, $this->twenty->id, $this->phone->id);

        expect($session->id)->toBe($this->session->id)
            ->and(OperatingSession::count())->toBe(1)
            ->and($this->session->fresh()->end_time)->toBeNull();
    });

    test('refuses a band-mode another station in the event is already on', function () {
        $other = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'HF 2', 'is_gota' => false]);
        OperatingSession::factory()->active()->create([
            'station_id' => $other->id,
            'operator_user_id' => User::factory()->create()->id,
            'band_id' => $this->forty->id,
            'mode_id' => $this->cw->id,
        ]);

        $this->actingAs($this->user);

        $result = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->forty->id, $this->cw->id)
            ->effects['returns'][0];

        expect($result)->toBe(['ok' => false, 'message' => '40m CW is already in use at HF 2.'])
            ->and($this->session->fresh()->end_time)->toBeNull();
    });

    test('lets a GOTA station share a band-mode', function () {
        $this->station->update(['is_gota' => true]);
        $other = Station::factory()->create(['event_configuration_id' => $this->config->id, 'is_gota' => false]);
        OperatingSession::factory()->active()->create([
            'station_id' => $other->id,
            'operator_user_id' => User::factory()->create()->id,
            'band_id' => $this->forty->id,
            'mode_id' => $this->phone->id,
        ]);

        $session = app(BandModeSwitchService::class)->switchTo($this->session->fresh(), $this->forty->id, $this->phone->id);

        expect($session->id)->not->toBe($this->session->id)
            ->and($session->band_id)->toBe($this->forty->id);
    });

    test('refuses to switch at a station marked unavailable', function () {
        $this->station->update(['unavailable_reason' => 'equipment_failure']);
        $this->actingAs($this->user);

        $result = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->forty->id, $this->phone->id)
            ->effects['returns'][0];

        expect($result['ok'])->toBeFalse()
            ->and($this->session->fresh()->end_time)->toBeNull();
    });

    test('a contact queued before the switch still syncs to the old session', function () {
        $this->actingAs($this->user);
        $this->session->update(['start_time' => now()->subHour()]);
        $qsoTime = now()->subMinute();

        app(BandModeSwitchService::class)->switchTo($this->session, $this->forty->id, $this->phone->id);

        $this->postJson('/logging/contacts', [
            'uuid' => (string) Str::uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 75,
            'qso_time' => $qsoTime->toISOString(),
        ])->assertCreated();

        expect(Contact::sole()->operating_session_id)->toBe($this->session->id);
    });

    test('the session bar has band and mode pickers instead of the end-session hint', function () {
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertSeeHtml('aria-label="Band"')
            ->assertSeeHtml('aria-label="Mode"')
            ->assertSeeHtml('<option value="'.$this->forty->id.'"')
            ->assertSeeHtml('touch-manipulation')
            ->assertDontSee('end this session and start a new one');
    });
});

describe('advanced fields', function () {
    test('the contact queue keeps its state when the component re-renders', function () {
        $this->actingAs($this->user);

        // x-data carries the server clock, which changes every render; without
        // wire:ignore.self each poll would restart the queue and wipe typed fields.
        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertSeeHtml('wire:ignore.self x-data="contactQueue(');
    });

    test('the panel has keyboard-friendly fields with the right mobile keyboards', function () {
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertSeeHtml('data-advanced-fields')
            ->assertSeeHtml('id="log-frequency"')
            ->assertSeeHtml('inputmode="decimal"')
            ->assertSeeHtml('id="log-rst-sent"')
            ->assertSeeHtml('id="log-rst-received"')
            ->assertSeeHtml('id="log-grid"')
            ->assertSeeHtml('id="log-contact-name"')
            ->assertSeeHtml('id="log-notes"')
            ->assertSeeHtml('aria-controls="log-advanced-panel"')
            ->assertSeeHtml('band_ranges: ')
            ->assertSee('Alt+A');
    });

    test('the log shows a contact\'s advanced fields and carries them for recall', function () {
        $this->actingAs($this->user);

        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'section_id' => $this->section->id,
            'qso_time' => now(),
            'frequency_hz' => 14_250_000,
            'rst_sent' => '59',
            'rst_received' => '57',
            'grid_square' => 'FN31pr',
            'contact_name' => 'Bob',
        ]);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertSee('14.25 · 59/57 · FN31pr · Bob')
            ->assertSeeHtml('data-advanced="')
            ->assertSeeHtml(e(json_encode($contact->only(['frequency_hz', 'rst_sent', 'rst_received', 'grid_square', 'contact_name', 'notes']))));
    });

    test('editing a recalled contact saves its advanced fields and audits them', function () {
        $this->actingAs($this->user);

        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'section_id' => $this->section->id,
            'callsign' => 'W1AW',
            'exchange_class' => '3A',
            'qso_time' => now(),
            'rst_sent' => '59',
        ]);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('updateContact', $contact->id, 'W1AW 3A CT', [
                'frequency_hz' => 14_250_000,
                'rst_sent' => '57',
                'rst_received' => '',
                'grid_square' => 'fn31',
                'contact_name' => 'Bob',
                'notes' => null,
            ])
            ->assertSet('parseError', '');

        expect($contact->fresh())
            ->frequency_hz->toBe(14_250_000)
            ->rst_sent->toBe('57')
            ->rst_received->toBeNull()
            ->grid_square->toBe('FN31')
            ->contact_name->toBe('Bob');

        $audit = AuditLog::where('action', 'contact.updated')->sole();
        expect($audit->old_values['rst_sent'])->toBe('59')
            ->and($audit->new_values['rst_sent'])->toBe('57');
    });

    test('editing a recalled contact rejects a frequency on another band', function () {
        $this->actingAs($this->user);

        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'section_id' => $this->section->id,
            'callsign' => 'W1AW',
            'qso_time' => now(),
        ]);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('updateContact', $contact->id, 'W1XYZ 3A CT', ['frequency_hz' => 7_200_000])
            ->assertSet('parseError', '7.2 MHz is not on 20m.');

        expect($contact->fresh()->callsign)->toBe('W1AW');
    });

    test('editing a recalled contact without advanced fields leaves them alone', function () {
        $this->actingAs($this->user);

        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'section_id' => $this->section->id,
            'qso_time' => now(),
            'rst_sent' => '59',
        ]);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('updateContact', $contact->id, 'W1XYZ 3A CT');

        expect($contact->fresh())
            ->callsign->toBe('W1XYZ')
            ->rst_sent->toBe('59');
    });
});
