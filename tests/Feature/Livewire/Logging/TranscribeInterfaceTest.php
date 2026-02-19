<?php

use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);
    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );
    Section::firstOrCreate(
        ['code' => 'ME'],
        ['name' => 'Maine', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->has(
        EventConfiguration::factory()->has(
            Station::factory(),
            'stations'
        ),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->station = $this->event->eventConfiguration->stations->first();
});

test('working time pre-fills to event start time', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->assertSet('workingTime', $this->event->start_time->format('Y-m-d\TH:i'));
});

test('contact time inherits working time when working time changes', function () {
    $this->actingAs($this->user);
    $newTime = now()->subHours(6)->format('Y-m-d\TH:i');

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('workingTime', $newTime)
        ->assertSet('contactTime', $newTime);
});

test('rejects contact time outside event bounds with buffer', function () {
    $this->actingAs($this->user);

    $tooEarly = $this->event->start_time->copy()->subMinutes(10)->format('Y-m-d\TH:i');

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('contactTime', $tooEarly)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertHasErrors(['contactTime']);
});

test('allows contact time within 5 minute buffer before start', function () {
    $this->actingAs($this->user);

    $justBeforeStart = $this->event->start_time->copy()->subMinutes(4)->format('Y-m-d\TH:i');

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('contactTime', $justBeforeStart)
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertHasNoErrors(['contactTime']);
});

test('saves contact with is_transcribed true', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact');

    $this->assertDatabaseHas('contacts', [
        'callsign' => 'W5XYZ',
        'is_transcribed' => true,
    ]);
});

test('creates synthetic operating session on first save', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact');

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $this->station->id,
        'is_transcription' => true,
    ]);
});

test('reuses existing transcription session on subsequent saves', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->set('exchangeInput', 'K1ABC 1B ME')
        ->call('logContact');

    $this->assertDatabaseCount('operating_sessions', 1);
    $this->assertDatabaseCount('contacts', 2);
});

test('archived event shows read-only message', function () {
    $this->actingAs($this->user);

    $archivedEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    $archivedStation = $archivedEvent->eventConfiguration->stations->first();

    Livewire::test(TranscribeInterface::class, ['station' => $archivedStation])
        ->assertSee('This event is archived');
});
