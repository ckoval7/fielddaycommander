<?php

use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\OperatingSession;
use App\Models\Shift;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Scoring\Rules\WinterFieldDay2026;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance(DemoSeeder::EVENT_TYPE_KEY, 'WFD');

    $this->seed(DemoSeeder::class);
});

test('seeds a Winter Field Day event as the active event', function () {
    $event = Event::where('is_active', true)->firstOrFail();

    expect($event->eventType->code)->toBe('WFD');
    expect($event->name)->toContain('Winter Field Day');
    expect($event->start_time->isPast())->toBeTrue();
    expect($event->end_time->isFuture())->toBeTrue();
});

test('configures a WFD operating class with no GOTA station', function () {
    $config = Event::where('is_active', true)->firstOrFail()->eventConfiguration;

    expect($config->operatingClass->code)->toBe('O');
    expect($config->operatingClass->event_type_id)->toBe($config->event->event_type_id);
    expect($config->has_gota_station)->toBeFalse();
    expect($config->gota_callsign)->toBeNull();
});

test('seeds no GOTA station', function () {
    expect(Station::where('is_gota', true)->count())->toBe(0);
    expect(Station::count())->toBe(5);
});

test('runs entirely on alternative power', function () {
    $config = Event::where('is_active', true)->firstOrFail()->eventConfiguration;

    expect($config->uses_commercial_power)->toBeFalse();
    expect($config->uses_generator)->toBeTrue();
    expect($config->uses_solar)->toBeTrue();
});

test('logs contacts with Winter Field Day exchange classes', function () {
    $classes = Contact::pluck('exchange_class');

    expect($classes)->not->toBeEmpty();

    $classes->each(function (string $class) {
        expect($class)->toMatch('/^\d{1,2}[HIOM]$/');
    });
});

test('scores contacts using the WFD per-mode point values', function () {
    Contact::with('mode')->each(function (Contact $contact) {
        expect($contact->points)->toBe((int) $contact->mode->points_wfd);
    });
});

test('claims only manually-triggered objectives', function () {
    $config = Event::where('is_active', true)->firstOrFail()->eventConfiguration;

    $claimed = EventBonus::where('event_configuration_id', $config->id)->with('bonusType')->get();

    expect($claimed)->not->toBeEmpty();

    $claimed->each(function (EventBonus $bonus) {
        expect($bonus->bonusType->trigger_type)->toBe('manual');
        expect($bonus->bonusType->objective_multiplier)->not->toBeNull();
        expect($bonus->is_verified)->toBeTrue();
    });
});

test('produces a positive score under the WFD ruleset', function () {
    $config = Event::where('is_active', true)->firstOrFail()->eventConfiguration;

    $breakdown = app(WinterFieldDay2026::class)->score($config);

    expect($breakdown->total)->toBeGreaterThan(0);
});

test('seeds a shift schedule with WFD-appropriate roles', function () {
    $roleNames = ShiftRole::pluck('name');

    expect($roleNames)->not->toBeEmpty();
    expect($roleNames)->toContain('Operator', 'Event Manager', 'Station Captain');
    expect($roleNames)->not->toContain('GOTA Coach');
    expect($roleNames)->not->toContain('Safety Officer');

    expect(Shift::count())->toBeGreaterThan(0);
});

test('leaves open operating sessions for the simulator', function () {
    expect(OperatingSession::whereNull('end_time')->count())->toBe(3);
});
