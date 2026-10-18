<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Events\ContactLogged;
use App\Livewire\Dashboard\Widgets\BandModeGrid;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day'],
    );

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);

    $this->mode = Mode::where('name', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $this->user = User::factory()->create();

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
});

// --- BandModeGrid widget listener tests ---

test('BandModeGrid registers echo listener for signed-in viewers when broadcasting is available', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);
    $this->actingAs($this->user);

    $component = Livewire::test(BandModeGrid::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:event.{$this->event->id},.ContactLogged");
});

test('BandModeGrid relies on polling for guests', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);

    $component = Livewire::test(BandModeGrid::class)
        ->assertSeeHtml('wire:poll.visible.30s');

    expect($component->instance()->getListeners())->toBe([]);
});

test('BandModeGrid handleContactLogged does not error', function () {
    $component = Livewire::test(BandModeGrid::class);

    $component->call('handleContactLogged', [
        'qso_count' => 5,
        'callsign' => 'W1AW',
    ]);

    $component->assertSet('hasError', false);
});

// --- ContactLogged event integration tests ---

test('ContactLogged event is dispatched with correct event ID on contact sync', function () {
    EventFacade::fake([ContactLogged::class]);

    $permission = Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    $this->actingAs($this->user);

    $this->postJson('/logging/contacts', [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'exchange_class' => '3A',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ])->assertCreated();

    EventFacade::assertDispatched(ContactLogged::class, function (ContactLogged $event) {
        return $event->event->id === $this->event->id;
    });
});

test('ContactLogged broadcastOn uses private channel with event ID', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
    ]);

    $event = new ContactLogged($contact, $this->event);
    $channels = $event->broadcastOn();

    expect($channels[0]->name)->toBe("private-event.{$this->event->id}");
});

test('ContactLogged broadcastWith contains required keys', function () {
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'K5ABC',
        'section_id' => $this->section->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    $contact->load(['band', 'mode', 'section']);
    $event = new ContactLogged($contact, $this->event);
    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'contact_id', 'callsign', 'band', 'mode', 'section',
        'points', 'is_duplicate', 'timestamp', 'qso_count',
    ]);
    expect($data['callsign'])->toBe('K5ABC');
    expect($data['points'])->toBe(2);
});
