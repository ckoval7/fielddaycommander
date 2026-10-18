<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('scopeForEvent', function () {
    test('filters contacts by event configuration ID', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        $contact1 = Contact::factory()->create([
            'event_configuration_id' => $event1->id,
        ]);
        $contact2 = Contact::factory()->create([
            'event_configuration_id' => $event1->id,
        ]);
        $contact3 = Contact::factory()->create([
            'event_configuration_id' => $event2->id,
        ]);

        $results = Contact::forEvent($event1->id)->get();

        expect($results)->toHaveCount(2)
            ->and($results->pluck('id')->toArray())->toContain($contact1->id, $contact2->id)
            ->and($results->pluck('id')->toArray())->not->toContain($contact3->id);
    });

    test('returns empty collection when no contacts for event', function () {
        $event = EventConfiguration::factory()->create();

        $results = Contact::forEvent($event->id)->get();

        expect($results)->toBeEmpty();
    });

    test('works with other query constraints', function () {
        $event = EventConfiguration::factory()->create();
        $band = Band::first() ?? Band::create([
            'name' => '20m',
            'meters' => 20,
            'frequency_mhz' => 14.175,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'band_id' => $band->id,
            'callsign' => 'W1AW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'band_id' => $band->id,
            'callsign' => 'K1ZZ',
        ]);

        $results = Contact::forEvent($event->id)
            ->where('callsign', 'W1AW')
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->callsign)->toBe('W1AW');
    });
});

describe('scopeNotDuplicate', function () {
    test('excludes duplicate contacts', function () {
        $event = EventConfiguration::factory()->create();

        $contact1 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
        ]);
        $contact2 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
        ]);
        $contact3 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
        ]);

        $results = Contact::notDuplicate()->get();

        expect($results)->toHaveCount(2)
            ->and($results->pluck('id')->toArray())->toContain($contact1->id, $contact2->id)
            ->and($results->pluck('id')->toArray())->not->toContain($contact3->id);
    });

    test('returns all contacts when none are duplicates', function () {
        $event = EventConfiguration::factory()->create();

        Contact::factory(3)->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
        ]);

        $results = Contact::notDuplicate()->get();

        expect($results)->toHaveCount(3);
    });

    test('returns empty collection when all contacts are duplicates', function () {
        $event = EventConfiguration::factory()->create();

        Contact::factory(3)->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
        ]);

        $results = Contact::notDuplicate()->get();

        expect($results)->toBeEmpty();
    });

    test('works with other scopes', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => false,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event2->id,
            'is_duplicate' => false,
        ]);

        $results = Contact::forEvent($event1->id)
            ->notDuplicate()
            ->get();

        expect($results)->toHaveCount(1);
    });
});

describe('scopeDuplicatesOnly', function () {
    test('returns only duplicate contacts', function () {
        $event = EventConfiguration::factory()->create();

        $contact1 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
        ]);
        $contact2 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
        ]);
        $contact3 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
        ]);

        $results = Contact::duplicatesOnly()->get();

        expect($results)->toHaveCount(2)
            ->and($results->pluck('id')->toArray())->toContain($contact1->id, $contact2->id)
            ->and($results->pluck('id')->toArray())->not->toContain($contact3->id);
    });

    test('returns empty collection when no duplicates', function () {
        $event = EventConfiguration::factory()->create();

        Contact::factory(3)->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
        ]);

        $results = Contact::duplicatesOnly()->get();

        expect($results)->toBeEmpty();
    });

    test('returns all contacts when all are duplicates', function () {
        $event = EventConfiguration::factory()->create();

        Contact::factory(3)->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
        ]);

        $results = Contact::duplicatesOnly()->get();

        expect($results)->toHaveCount(3);
    });

    test('works with other scopes', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => false,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event2->id,
            'is_duplicate' => true,
        ]);

        $results = Contact::forEvent($event1->id)
            ->duplicatesOnly()
            ->get();

        expect($results)->toHaveCount(1);
    });
});

describe('scopeChronological', function () {
    test('orders contacts by qso_time descending', function () {
        $event = EventConfiguration::factory()->create();

        $contact1 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'qso_time' => now()->subHours(3),
            'callsign' => 'OLDEST',
        ]);
        $contact2 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'qso_time' => now()->subHour(),
            'callsign' => 'NEWEST',
        ]);
        $contact3 = Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'qso_time' => now()->subHours(2),
            'callsign' => 'MIDDLE',
        ]);

        $results = Contact::chronological()->get();

        expect($results)->toHaveCount(3)
            ->and($results[0]->callsign)->toBe('NEWEST')
            ->and($results[1]->callsign)->toBe('MIDDLE')
            ->and($results[2]->callsign)->toBe('OLDEST');
    });

    test('handles contacts with same qso_time', function () {
        $event = EventConfiguration::factory()->create();
        $time = now();

        Contact::factory(3)->create([
            'event_configuration_id' => $event->id,
            'qso_time' => $time,
        ]);

        $results = Contact::chronological()->get();

        expect($results)->toHaveCount(3);
    });

    test('works with other scopes', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'qso_time' => now()->subHours(2),
            'callsign' => 'EVENT1_OLD',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'qso_time' => now()->subHour(),
            'callsign' => 'EVENT1_NEW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event2->id,
            'qso_time' => now()->subMinutes(30),
            'callsign' => 'EVENT2_NEWEST',
        ]);

        $results = Contact::forEvent($event1->id)
            ->chronological()
            ->get();

        expect($results)->toHaveCount(2)
            ->and($results[0]->callsign)->toBe('EVENT1_NEW')
            ->and($results[1]->callsign)->toBe('EVENT1_OLD');
    });
});

describe('combined scope usage', function () {
    test('can chain all scopes together', function () {
        $event1 = EventConfiguration::factory()->create();
        $event2 = EventConfiguration::factory()->create();

        // Event 1: 2 valid + 1 duplicate
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => false,
            'qso_time' => now()->subHours(3),
            'callsign' => 'VALID_OLD',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => false,
            'qso_time' => now()->subHour(),
            'callsign' => 'VALID_NEW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event1->id,
            'is_duplicate' => true,
            'qso_time' => now()->subMinutes(30),
            'callsign' => 'DUPLICATE',
        ]);

        // Event 2: should be excluded
        Contact::factory()->create([
            'event_configuration_id' => $event2->id,
            'is_duplicate' => false,
            'qso_time' => now(),
            'callsign' => 'OTHER_EVENT',
        ]);

        $results = Contact::forEvent($event1->id)
            ->notDuplicate()
            ->chronological()
            ->get();

        expect($results)->toHaveCount(2)
            ->and($results[0]->callsign)->toBe('VALID_NEW')
            ->and($results[1]->callsign)->toBe('VALID_OLD');
    });

    test('forEvent with duplicatesOnly and chronological', function () {
        $event = EventConfiguration::factory()->create();

        Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
            'qso_time' => now()->subHours(2),
            'callsign' => 'DUP_OLD',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => true,
            'qso_time' => now()->subHour(),
            'callsign' => 'DUP_NEW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $event->id,
            'is_duplicate' => false,
            'qso_time' => now(),
            'callsign' => 'NOT_DUP',
        ]);

        $results = Contact::forEvent($event->id)
            ->duplicatesOnly()
            ->chronological()
            ->get();

        expect($results)->toHaveCount(2)
            ->and($results[0]->callsign)->toBe('DUP_NEW')
            ->and($results[1]->callsign)->toBe('DUP_OLD');
    });
});
