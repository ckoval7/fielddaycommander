<?php

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

uses(RefreshDatabase::class);

beforeEach(function () {
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

    $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
});

test('logContact dispatches contact-queued browser event instead of saving to database', function () {
    $this->actingAs($this->user);

    $result = \Livewire\Livewire::test(\App\Livewire\Logging\LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1AW 3A CT')
        ->call('logContact');

    // Should NOT create a contact in the database
    expect(Contact::where('callsign', 'W1AW')->count())->toBe(0);

    // Should dispatch browser event with parsed data
    $result->assertDispatched('contact-queued');
});

test('logContact still validates and shows parse errors', function () {
    $this->actingAs($this->user);

    \Livewire\Livewire::test(\App\Livewire\Logging\LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'BADFORMAT')
        ->call('logContact')
        ->assertSet('parseError', 'Exchange must contain callsign, class, and section (e.g. W1AW 3A CT)');
});

test('logContact dispatches contact-logged event for UI reset', function () {
    $this->actingAs($this->user);

    \Livewire\Livewire::test(\App\Livewire\Logging\LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1AW 3A CT')
        ->call('logContact')
        ->assertDispatched('contact-logged');
});
