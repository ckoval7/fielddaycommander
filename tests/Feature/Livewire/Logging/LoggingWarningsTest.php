<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\StationSelect;
use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\LoggingWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);
    $this->phone = Mode::where('category', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);
    $this->cw = Mode::where('category', 'CW')->first() ?? Mode::create([
        'name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2,
    ]);
    Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'callsign' => 'W1CLUB',
        'gota_callsign' => 'W1GOTA',
    ]);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Station 1',
    ]);

    $this->user = User::factory()->create(['call_sign' => 'K1OPR']);
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phone->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);
});

test('base callsign strips designators and prefixes', function (string $input, string $expected) {
    expect(LoggingWarningService::baseCallsign($input))->toBe($expected);
})->with([
    ['w1abc', 'W1ABC'],
    ['W1ABC/P', 'W1ABC'],
    ['W1ABC/M', 'W1ABC'],
    ['W1ABC/QRP', 'W1ABC'],
    ['W1ABC/7', 'W1ABC'],
    ['VE3/W1ABC', 'W1ABC'],
]);

test('live logging warns for event, gota, operator, shift and guest operator callsigns', function (string $callsign) {
    $shiftUser = User::factory()->create(['call_sign' => 'N2SHF']);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->config->id]);
    ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $shiftUser->id]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'gota_operator_callsign' => 'KD9GST',
    ]);

    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', "{$callsign} 1B CT")
        ->assertSet('participantWarning', strtoupper($callsign).' is a participant in this event — contacts with your own participants don\'t count for QSO credit.')
        ->assertSee('is a participant in this event');
})->with(['W1CLUB', 'W1GOTA', 'k1opr/p', 'N2SHF', 'KD9GST']);

test('live logging does not warn for other callsigns or no-show signups', function () {
    $noShow = User::factory()->create(['call_sign' => 'N3NOS']);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->config->id]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $noShow->id,
        'status' => ShiftAssignment::STATUS_NO_SHOW,
    ]);

    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->assertSet('participantWarning', '')
        ->set('exchangeInput', 'N3NOS 1B CT')
        ->assertSet('participantWarning', '')
        ->assertDontSee('is a participant in this event');
});

test('participant warning clears when the input is cleared', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1CLUB 1B CT')
        ->assertNotSet('participantWarning', '')
        ->call('clearInput')
        ->assertSet('participantWarning', '');
});

test('transcribe warns for a participant callsign but still logs the contact', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->phone->id)
        ->set('exchangeInput', 'K1OPR 1B CT')
        ->assertSee('is a participant in this event')
        ->call('logContact');

    expect(Contact::where('callsign', 'K1OPR')->exists())->toBeTrue();
    $component->assertSet('participantWarning', '');
});

test('transcribe does not warn for an unrelated callsign', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->phone->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->assertSet('participantWarning', '')
        ->assertDontSee('is a participant in this event');
});

test('station select warns when another station is on the same band and mode group', function () {
    $this->actingAs($this->user);

    $other = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Station 2',
    ]);
    $ssb = Mode::create(['name' => 'SSB', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $fm = Mode::create(['name' => 'FM', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    OperatingSession::factory()->active()->create([
        'station_id' => $other->id,
        'band_id' => $this->band->id,
        'mode_id' => $ssb->id,
    ]);
    $this->session->update(['end_time' => now()]);

    $third = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Station 3']);

    Livewire::test(StationSelect::class)
        ->set('selectedStationId', $third->id)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $fm->id)
        ->assertSee("Station 2 is also on {$this->band->name} Phone — only one transmitter per band-mode at a time.")
        ->set('selectedModeId', $this->cw->id)
        ->assertDontSee('only one transmitter per band-mode');
});

test('live logging header warns about a conflicting active session and not otherwise', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSet('bandModeWarning', '')
        ->assertDontSee('only one transmitter per band-mode');

    $other = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Station 2',
    ]);
    $otherSession = OperatingSession::factory()->active()->create([
        'station_id' => $other->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phone->id,
    ]);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSee('Station 2 is also on')
        ->assertSee('only one transmitter per band-mode at a time');

    $otherSession->update(['end_time' => now()]);
    $component->call('refreshBandModeWarning')->assertSet('bandModeWarning', '');
});

test('gota stations and other events are ignored for band-mode warnings', function () {
    $this->actingAs($this->user);

    $gota = Station::factory()->gota()->create(['event_configuration_id' => $this->config->id]);
    OperatingSession::factory()->active()->create([
        'station_id' => $gota->id, 'band_id' => $this->band->id, 'mode_id' => $this->phone->id,
    ]);
    $otherConfig = EventConfiguration::factory()->create(['event_id' => Event::factory()->create()->id]);
    $foreign = Station::factory()->create(['event_configuration_id' => $otherConfig->id]);
    OperatingSession::factory()->active()->create([
        'station_id' => $foreign->id, 'band_id' => $this->band->id, 'mode_id' => $this->phone->id,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSet('bandModeWarning', '');
});
