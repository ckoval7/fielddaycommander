<?php

use App\Events\ContactLogged;
use App\Livewire\Dashboard\Widgets\BandModeGrid;
use App\Livewire\Dashboard\Widgets\QsoCount;
use App\Livewire\Dashboard\Widgets\RecentContacts;
use App\Livewire\Dashboard\Widgets\Score;
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
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
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

// --- QsoCount widget listener tests ---

test('QsoCount registers echo listener for active event', function () {
    $component = Livewire::test(QsoCount::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:event.{$this->event->id},ContactLogged");
});

test('QsoCount has no listeners when no active event', function () {
    // Make event non-active (in the future)
    $this->event->update([
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(31),
    ]);

    $component = Livewire::test(QsoCount::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toBeEmpty();
});

test('QsoCount handleContactLogged updates cached count from payload', function () {
    $component = Livewire::test(QsoCount::class);

    $component->call('handleContactLogged', [
        'qso_count' => 42,
        'callsign' => 'W1AW',
        'band' => '20m',
        'mode' => 'Phone',
    ]);

    expect($component->get('cachedCount'))->toBe(42);
});

test('QsoCount handleContactLogged dispatches qso-logged browser event', function () {
    $component = Livewire::test(QsoCount::class);

    $component->call('handleContactLogged', [
        'qso_count' => 10,
        'callsign' => 'K5ABC',
    ]);

    $component->assertDispatched('qso-logged');
});

// --- Score widget listener tests ---

test('Score registers echo listener for active event', function () {
    $component = Livewire::test(Score::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:event.{$this->event->id},ContactLogged");
});

test('Score handleContactLogged does not error', function () {
    $component = Livewire::test(Score::class);

    $component->call('handleContactLogged', [
        'qso_count' => 5,
        'callsign' => 'W1AW',
    ]);

    $component->assertSet('hasError', false);
});

// --- RecentContacts widget listener tests ---

test('RecentContacts registers echo listener for active event', function () {
    $component = Livewire::test(RecentContacts::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:event.{$this->event->id},ContactLogged");
});

test('RecentContacts handleContactLogged does not error', function () {
    $component = Livewire::test(RecentContacts::class);

    $component->call('handleContactLogged', [
        'qso_count' => 5,
        'callsign' => 'W1AW',
    ]);

    $component->assertSet('hasError', false);
});

// --- BandModeGrid widget listener tests ---

test('BandModeGrid registers echo listener for active event', function () {
    $component = Livewire::test(BandModeGrid::class);

    $listeners = $component->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:event.{$this->event->id},ContactLogged");
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

    $this->actingAs($this->user);

    $this->postJson('/api/logging/contacts', [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'received_exchange' => 'W1AW 3A CT',
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
