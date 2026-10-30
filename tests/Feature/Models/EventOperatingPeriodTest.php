<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Components\EventCountdown;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Services\DuplicateCheckService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * ARRL Field Day rule 3.2: a station that started setup before 1800 UTC
 * Saturday may operate only 24 consecutive hours from its first contact.
 */
beforeEach(function () {
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true]);
    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::firstOrCreate(['name' => 'SSB'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);

    $this->event = Event::factory()->create([
        'year' => 2026,
        'rules_version' => '2026',
        'start_time' => '2026-06-27 18:00:00',
        'end_time' => '2026-06-28 20:59:00',
        'setup_allowed_from' => '2026-06-26 00:00:00',
        'setup_started_early' => true,
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id]);

    $this->logAt = fn (string $time, array $attributes = []): Contact => Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => $time,
        ...$attributes,
    ]);
});

test('with the toggle off the operating period ends at the event end', function () {
    $this->event->update(['setup_started_early' => false]);
    ($this->logAt)('2026-06-27 18:05:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
});

test('with the toggle on and no contacts yet the operating period ends at the event end', function () {
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
});

test('with the toggle on the operating period ends 24 hours after the first contact', function () {
    ($this->logAt)('2026-06-27 19:30:00');
    ($this->logAt)('2026-06-27 18:42:10');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 18:42:10');
});

test('a GOTA contact can be the first contact', function () {
    ($this->logAt)('2026-06-27 19:30:00');
    ($this->logAt)('2026-06-27 18:15:00', ['is_gota_contact' => true]);

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 18:15:00');
});

test('contacts before the start and deleted contacts do not start the 24 hours', function () {
    ($this->logAt)('2026-06-27 17:00:00');
    ($this->logAt)('2026-06-27 18:10:00')->delete();
    ($this->logAt)('2026-06-27 19:00:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 19:00:00');
});

test('the operating period never runs past the event end', function () {
    ($this->logAt)('2026-06-27 22:00:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
});

test('logging, moving and deleting contacts refreshes the cached first contact', function () {
    $later = ($this->logAt)('2026-06-27 20:00:00');
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:00:00');

    $earlier = ($this->logAt)('2026-06-27 19:00:00');
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 19:00:00');

    $earlier->update(['qso_time' => '2026-06-27 18:30:00']);
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 18:30:00');

    $earlier->delete();
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:00:00');

    $later->delete();
    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
});

test('the first contact is looked up once, not on every call', function () {
    ($this->logAt)('2026-06-27 18:30:00');

    $events = Event::with('eventConfiguration')->whereKey($this->event->id)->get()
        ->concat(Event::with('eventConfiguration')->whereKey($this->event->id)->get());

    DB::enableQueryLog();

    foreach (range(1, 3) as $ignored) {
        $events->each(fn (Event $event) => $event->operatingEndsAt());
    }

    $minQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains(strtolower($query['query']), 'min('));
    expect($minQueries)->toHaveCount(1);
});

test('a contact after the 24 hours earns no points while one inside them does', function () {
    ($this->logAt)('2026-06-27 18:30:00', ['callsign' => 'K1ABC']);

    $dupes = app(DuplicateCheckService::class);
    $session = OperatingSession::factory()->create(['station_id' => $this->station->id]);

    $inside = new Contact([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => '2026-06-28 18:29:00',
        'callsign' => 'W1INS',
        'exchange_class' => '1A',
    ]);
    $after = new Contact([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'qso_time' => '2026-06-28 18:31:00',
        'callsign' => 'W1AFT',
        'exchange_class' => '1A',
    ]);

    $dupes->save($inside);
    $dupes->save($after);

    expect($inside->fresh()->points)->toBeGreaterThan(0)
        ->and($after->fresh()->points)->toBe(0)
        ->and($after->fresh()->attentionReasons())->toContain('Outside event window')
        ->and($inside->fresh()->attentionReasons())->not->toContain('Outside event window');

    $flagged = Contact::query()->where('event_configuration_id', $this->config->id)
        ->needsAttention($this->event->fresh())
        ->pluck('callsign');
    expect($flagged->all())->toBe(['W1AFT']);
});

test('the header countdown counts to the end of the 24 hours once a contact exists', function () {
    $this->travelTo(Carbon::parse('2026-06-27 20:00:00'));

    expect(Livewire::test(EventCountdown::class)->get('targetTimestamp'))
        ->toBe(Carbon::parse('2026-06-28 20:59:00')->timestamp);

    ($this->logAt)('2026-06-27 18:30:00');

    $countdown = Livewire::test(EventCountdown::class);
    expect($countdown->get('targetTimestamp'))->toBe(Carbon::parse('2026-06-28 18:30:00')->timestamp)
        ->and($countdown->get('state'))->toBe('active');

    $this->travelTo(Carbon::parse('2026-06-28 19:00:00'));

    expect(Livewire::test(EventCountdown::class)->get('state'))->toBe('ended');
});

test('expired session cleanup closes sessions when the 24 hours run out', function () {
    ($this->logAt)('2026-06-27 18:30:00');
    $session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'start_time' => '2026-06-28 10:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-06-28 18:00:00'));
    $this->artisan('sessions:close-expired')->assertSuccessful();
    expect($session->fresh()->end_time)->toBeNull();

    $this->travelTo(Carbon::parse('2026-06-28 19:00:00'));
    $this->artisan('sessions:close-expired')->assertSuccessful();
    expect($session->fresh()->end_time->toDateTimeString())->toBe('2026-06-28 18:30:00');
});

test('Class C, D and E stations always operate 24 hours from the first contact', function (string $code) {
    $this->event->update(['setup_started_early' => false]);
    $this->config->update([
        'operating_class_id' => OperatingClass::where('event_type_id', $this->event->event_type_id)->where('code', $code)->value('id'),
    ]);
    ($this->logAt)('2026-06-27 18:30:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 18:30:00');
})->with(['C', 'D', 'E']);

test('a Class B or F station that waited for the start keeps the full period', function (string $code) {
    $this->event->update(['setup_started_early' => false]);
    $this->config->update([
        'operating_class_id' => OperatingClass::where('event_type_id', $this->event->event_type_id)->where('code', $code)->value('id'),
    ]);
    ($this->logAt)('2026-06-27 18:30:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
})->with(['B', 'F']);

test('a Winter Field Day event is never held to 24 hours', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'description' => 'Winter Field Day', 'is_active' => true]);
    $this->event->update(['event_type_id' => $wfd->id, 'setup_started_early' => false]);
    $this->config->update([
        'operating_class_id' => OperatingClass::firstOrCreate(['event_type_id' => $wfd->id, 'code' => 'H'], ['name' => 'Home', 'description' => 'Home'])->id,
    ]);
    ($this->logAt)('2026-06-27 18:30:00');

    expect($this->event->fresh()->operatingEndsAt()->toDateTimeString())->toBe('2026-06-28 20:59:00');
});

test('expired session cleanup closes a Class D session when its 24 hours run out', function () {
    $this->event->update(['setup_started_early' => false]);
    $this->config->update([
        'operating_class_id' => OperatingClass::where('event_type_id', $this->event->event_type_id)->where('code', 'D')->value('id'),
    ]);
    ($this->logAt)('2026-06-27 18:30:00');
    $session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'start_time' => '2026-06-28 10:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-06-28 19:00:00'));
    $this->artisan('sessions:close-expired')->assertSuccessful();
    expect($session->fresh()->end_time->toDateTimeString())->toBe('2026-06-28 18:30:00');
});
