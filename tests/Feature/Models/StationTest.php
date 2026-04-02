<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;

describe('Station Relationships', function () {
    test('belongs to event configuration', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        expect($station->eventConfiguration)
            ->toBeInstanceOf(EventConfiguration::class)
            ->id->toBe($eventConfiguration->id);
    });

    test('belongs to primary radio equipment', function () {
        $radio = Equipment::factory()->create();
        $station = Station::factory()->create([
            'radio_equipment_id' => $radio->id,
        ]);

        expect($station->primaryRadio)
            ->toBeInstanceOf(Equipment::class)
            ->id->toBe($radio->id);
    });

    test('can have additional equipment through pivot', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $event = $eventConfiguration->event;
        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        $equipment1 = Equipment::factory()->create();
        $equipment2 = Equipment::factory()->create();

        // Attach equipment to station via equipment_event pivot
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $event->id,
            'station_id' => $station->id,
            'status' => 'delivered',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $event->id,
            'station_id' => $station->id,
            'status' => 'delivered',
        ]);

        expect($station->additionalEquipment)->toHaveCount(2);
        expect($station->additionalEquipment->pluck('id')->toArray())
            ->toContain($equipment1->id)
            ->toContain($equipment2->id);
    });

    test('has many operating sessions', function () {
        $this->seed([\Database\Seeders\BandSeeder::class, \Database\Seeders\ModeSeeder::class]);

        $station = Station::factory()->create();
        $band = Band::first();
        $mode = Mode::first();
        $user = User::factory()->create();

        OperatingSession::factory()->count(3)->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        expect($station->operatingSessions)->toHaveCount(3);
        expect($station->operatingSessions->first())
            ->toBeInstanceOf(OperatingSession::class);
    });

    test('has many contacts through operating sessions', function () {
        $this->seed([\Database\Seeders\BandSeeder::class, \Database\Seeders\ModeSeeder::class, \Database\Seeders\SectionSeeder::class]);

        $eventConfiguration = EventConfiguration::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);
        $band = Band::first();
        $mode = Mode::first();
        $section = Section::first();
        $user = User::factory()->create();

        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->count(5)->create([
            'event_configuration_id' => $eventConfiguration->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'section_id' => $section->id,
        ]);

        expect($station->contacts)->toHaveCount(5);
        expect($station->contacts->first())
            ->toBeInstanceOf(Contact::class);
    });
});

describe('Station Accessors', function () {
    test('equipment count returns count of additional equipment', function () {
        $eventConfiguration = EventConfiguration::factory()->create();
        $event = $eventConfiguration->event;
        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);

        // Create additional equipment
        $equipment1 = Equipment::factory()->create();
        $equipment2 = Equipment::factory()->create();
        $equipment3 = Equipment::factory()->create();

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $event->id,
            'station_id' => $station->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $event->id,
            'station_id' => $station->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment3->id,
            'event_id' => $event->id,
            'station_id' => $station->id,
        ]);

        expect($station->equipment_count)->toBe(3);
    });

    test('is active returns true when station has active sessions', function () {
        $this->seed([\Database\Seeders\BandSeeder::class, \Database\Seeders\ModeSeeder::class]);

        $station = Station::factory()->create();
        $band = Band::first();
        $mode = Mode::first();
        $user = User::factory()->create();

        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => now()->subHour(),
            'end_time' => null, // Active session
        ]);

        expect($station->is_active)->toBeTrue();
    });

    test('is active returns false when station has no active sessions', function () {
        $this->seed([\Database\Seeders\BandSeeder::class, \Database\Seeders\ModeSeeder::class]);

        $station = Station::factory()->create();
        $band = Band::first();
        $mode = Mode::first();
        $user = User::factory()->create();

        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(), // Ended session
        ]);

        expect($station->is_active)->toBeFalse();
    });

    test('contact count returns total qsos logged', function () {
        $this->seed([\Database\Seeders\BandSeeder::class, \Database\Seeders\ModeSeeder::class, \Database\Seeders\SectionSeeder::class]);

        $eventConfiguration = EventConfiguration::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);
        $band = Band::first();
        $mode = Mode::first();
        $section = Section::first();
        $user = User::factory()->create();

        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->count(10)->create([
            'event_configuration_id' => $eventConfiguration->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'section_id' => $section->id,
        ]);

        expect($station->contact_count)->toBe(10);
    });
});

describe('Station Scopes', function () {
    test('for event scope filters by event configuration id', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        $station1 = Station::factory()->create(['event_configuration_id' => $event1->id]);
        $station2 = Station::factory()->create(['event_configuration_id' => $event1->id]);
        $station3 = Station::factory()->create(['event_configuration_id' => $event2->id]);

        $filtered = Station::forEvent($event1->id)->get();

        expect($filtered)->toHaveCount(2);
        expect($filtered->pluck('id')->toArray())
            ->toContain($station1->id)
            ->toContain($station2->id)
            ->not->toContain($station3->id);
    });

    test('gota scope filters gota stations only', function () {
        Station::factory()->create(['is_gota' => true]);
        Station::factory()->create(['is_gota' => true]);
        Station::factory()->create(['is_gota' => false]);

        $gotaStations = Station::gota()->get();

        expect($gotaStations)->toHaveCount(2);
        expect($gotaStations->every(fn ($station) => $station->is_gota))->toBeTrue();
    });

    test('non gota scope filters non gota stations only', function () {
        Station::factory()->create(['is_gota' => true]);
        Station::factory()->create(['is_gota' => false]);
        Station::factory()->create(['is_gota' => false]);

        $nonGotaStations = Station::nonGota()->get();

        expect($nonGotaStations)->toHaveCount(2);
        expect($nonGotaStations->every(fn ($station) => ! $station->is_gota))->toBeTrue();
    });
});
