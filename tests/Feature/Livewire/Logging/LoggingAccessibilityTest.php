<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logging\LoggingInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);

    $this->phoneMode = Mode::where('name', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $this->cwMode = Mode::where('name', 'CW')->first() ?? Mode::create([
        'name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2,
    ]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->stxSection = Section::firstOrCreate(
        ['code' => 'STX'],
        ['name' => 'South Texas', 'region' => 'W5', 'country' => 'US', 'is_active' => true],
    );

    // Create active event
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $this->stxSection->id,
        'transmitter_count' => 3,
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Phone 1',
    ]);

    // Create user with permission
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    // Create active session
    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);
});

test('the logging page is headed by the station name', function () {
    $this->actingAs($this->user)
        ->get(route('logging.session', $this->session))
        ->assertOk()
        ->assertSeeInOrder(['<h1', 'Phone 1', '</h1>'], false);
});

test('the exchange input is named and linked to its help and warnings', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSeeHtml('aria-label="Exchange"')
        ->assertSeeHtml('aria-describedby="exchange-warnings exchange-help"')
        ->assertSeeHtml('<output id="exchange-warnings" class="sr-only" aria-live="polite"')
        ->assertSeeHtml('id="exchange-help" class="sr-only"')
        ->assertSeeHtml("x-init=\"\$watch('si'");
});

test('a dupe is put in the announced warning region', function () {
    $this->actingAs($this->user);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W5NEW',
        'section_id' => $this->section->id,
        'exchange_class' => '2A',
    ]);

    $html = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W5NEW')
        ->html();

    expect($html)->toMatch('/id="exchange-warnings"[^>]*>\s*(<!--.*?-->)?Dupe: W5NEW/');
});

test('sync status is spoken at every width', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSeeHtml('x-text="statusLabel" x-cloak class="text-xs text-base-content/70 max-sm:sr-only"')
        ->assertSeeHtml('<span x-show="!statusLabel" class="sr-only">All contacts synced</span>');
});

test('logged contacts can be recalled with a named button', function () {
    $this->actingAs($this->user);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K1ABC',
        'section_id' => $this->section->id,
        'exchange_class' => '2A',
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSeeHtml('@click.stop="recallByContactId('.$contact->id.')" aria-label="K1ABC, edit contact"');
});

test('editing a synced contact with a bad exchange shows and announces the error', function () {
    $this->actingAs($this->user);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K1ABC',
        'section_id' => $this->section->id,
        'exchange_class' => '2A',
    ]);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('updateContact', $contact->id, 'XYZ');

    $error = $component->get('parseError');

    expect($error)->not->toBe('')
        ->and($component->html())->toContain('<div class="sr-only" role="alert" aria-atomic="true">'.e(spokenAbbreviations($error)).'</div>');
});

test('saving and deleting a synced contact are announced', function () {
    $this->actingAs($this->user);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K1ABC',
        'section_id' => $this->section->id,
        'exchange_class' => '2A',
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('updateContact', $contact->id, 'K1ABC 3A CT')
        ->assertDispatched('announce', message: 'K1ABC saved')
        ->call('deleteContact', $contact->id)
        ->assertDispatched('announce', message: 'K1ABC deleted');
});
