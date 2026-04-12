<?php

use App\Events\ContactLogged;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
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

    $permission = Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);

    $this->contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);
});

test('ContactLogged event broadcasts on private event channel', function () {
    $event = new ContactLogged($this->contact, $this->event);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe("private-event.{$this->event->id}");
});

test('ContactLogged broadcastWith includes correct data', function () {
    $this->contact->load(['band', 'mode', 'section']);
    $event = new ContactLogged($this->contact, $this->event);
    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'contact_id', 'callsign', 'band', 'mode', 'section',
        'points', 'is_duplicate', 'timestamp', 'qso_count',
    ]);
    expect($data['contact_id'])->toBe($this->contact->id);
    expect($data['callsign'])->toBe('W1AW');
    expect($data['band'])->toBe('20m');
    expect($data['mode'])->toBe('Phone');
    expect($data['section'])->toBe('CT');
    expect($data['points'])->toBe(1);
    expect($data['is_duplicate'])->toBeFalse();
});

test('ContactLogged broadcastWith includes accurate qso count', function () {
    // Create additional contacts for same event config
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $this->contact->load(['band', 'mode', 'section']);
    $event = new ContactLogged($this->contact, $this->event);
    $data = $event->broadcastWith();

    // 1 original + 3 additional = 4
    expect($data['qso_count'])->toBe(4);
});

test('ContactLogged event is dispatched when syncing a contact via API', function () {
    EventFacade::fake([ContactLogged::class]);

    $this->actingAs($this->user);

    $this->postJson('/logging/contacts', [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'K5ABC',
        'section_id' => $this->section->id,
        'exchange_class' => '3A',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ])->assertCreated();

    EventFacade::assertDispatched(ContactLogged::class, function (ContactLogged $event) {
        return $event->contact->callsign === 'K5ABC'
            && $event->event->id === $this->event->id;
    });
});
