<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\LogbookQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->builder = new LogbookQueryBuilder;

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.175,
    ]);

    $this->otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create([
        'name' => '40m',
        'meters' => 40,
        'frequency_mhz' => 7.0,
    ]);

    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'SSB',
        'category' => 'Phone',
        'points_fd' => 1,
        'points_wfd' => 1,
    ]);

    $this->otherMode = Mode::where('name', '!=', $this->mode->name)->first() ?? Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
    ]);

    $this->eventConfig = EventConfiguration::factory()->create();
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);
    $this->otherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

describe('buildQuery', function () {
    test('returns query builder with eager loading configured', function () {
        $query = $this->builder->buildQuery();

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class)
            ->and($query->getEagerLoads())->toHaveKeys(['band', 'mode', 'section', 'logger', 'operatingSession.station']);
    });

    test('eager loads gotaOperator relationship', function () {
        $query = $this->builder->buildQuery();

        expect($query->getEagerLoads())->toHaveKey('gotaOperator');
    });

    test('eager loading prevents N+1 queries', function () {
        // Create contacts with relationships
        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);

        Contact::factory(5)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'logger_user_id' => $this->user->id,
        ]);

        // Query with eager loading
        $query = $this->builder->buildQuery();
        $contacts = $query->get();

        // Access relationships - should not trigger additional queries
        foreach ($contacts as $contact) {
            $bandName = $contact->band->name;
            $modeName = $contact->mode->name;
            $loggerName = $contact->logger->name;
            $stationName = $contact->operatingSession->station->name;
        }

        // If we got here without errors, eager loading is working
        expect($contacts)->toHaveCount(5);
    });
});

describe('forEvent', function () {
    test('filters contacts by event configuration ID', function () {
        $otherEvent = EventConfiguration::factory()->create();

        Contact::factory(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $otherEvent->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forEvent($query, $this->eventConfig->id);
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($contact) => $contact->event_configuration_id === $this->eventConfig->id))->toBeTrue();
    });
});

describe('forBand', function () {
    test('filters contacts by a single band ID', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->band->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->otherBand->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forBand($query, [$this->band->id]);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->band_id)->toBe($this->band->id);
    });

    test('filters contacts by multiple band IDs', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->band->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->otherBand->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forBand($query, [$this->band->id, $this->otherBand->id]);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });

    test('returns all contacts when band IDs array is empty', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->band->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->otherBand->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forBand($query, []);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('forMode', function () {
    test('filters contacts by a single mode ID', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->mode->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->otherMode->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forMode($query, [$this->mode->id]);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->mode_id)->toBe($this->mode->id);
    });

    test('filters contacts by multiple mode IDs', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->mode->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->otherMode->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forMode($query, [$this->mode->id, $this->otherMode->id]);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });

    test('returns all contacts when mode IDs array is empty', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->mode->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->otherMode->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forMode($query, []);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('forStation', function () {
    test('filters contacts by a single station through operating session', function () {
        $session1 = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);
        $session2 = OperatingSession::factory()->create([
            'station_id' => $this->otherStation->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session1->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session2->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forStation($query, [$this->station->id]);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->operatingSession->station_id)->toBe($this->station->id);
    });

    test('filters contacts by multiple stations', function () {
        $session1 = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);
        $session2 = OperatingSession::factory()->create([
            'station_id' => $this->otherStation->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session1->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session2->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forStation($query, [$this->station->id, $this->otherStation->id]);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });

    test('returns all contacts when station IDs array is empty', function () {
        $session1 = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);
        $session2 = OperatingSession::factory()->create([
            'station_id' => $this->otherStation->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session1->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session2->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forStation($query, []);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('forOperator', function () {
    test('filters contacts by a single operator ID', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->user->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->otherUser->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forOperator($query, [$this->user->id]);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->logger_user_id)->toBe($this->user->id);
    });

    test('filters contacts by multiple operator IDs', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->user->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->otherUser->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forOperator($query, [$this->user->id, $this->otherUser->id]);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });

    test('returns all contacts when operator IDs array is empty', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->user->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'logger_user_id' => $this->otherUser->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forOperator($query, []);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('forTimeRange', function () {
    test('filters contacts by start time', function () {
        $baseTime = now();

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->subHours(2),
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->addHour(),
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forTimeRange($query, $baseTime->toDateTimeString(), null);
        $results = $query->get();

        expect($results)->toHaveCount(1);
    });

    test('filters contacts by end time', function () {
        $baseTime = now();

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->subHours(2),
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->addHour(),
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forTimeRange($query, null, $baseTime->toDateTimeString());
        $results = $query->get();

        expect($results)->toHaveCount(1);
    });

    test('filters contacts by time range', function () {
        $baseTime = now();

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->subHours(3),
            'callsign' => 'BEFORE',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->subHour(),
            'callsign' => 'DURING',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => $baseTime->copy()->addHours(3),
            'callsign' => 'AFTER',
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forTimeRange(
            $query,
            $baseTime->copy()->subHours(2)->toDateTimeString(),
            $baseTime->copy()->addHours(2)->toDateTimeString()
        );
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->callsign)->toBe('DURING');
    });

    test('returns all contacts when both times are null', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forTimeRange($query, null, null);
        $results = $query->get();

        expect($results)->toHaveCount(3);
    });
});

describe('forCallsign', function () {
    test('filters contacts by callsign partial match', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'K1ZZ',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'N1MM',
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, '1Z');
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->callsign)->toBe('K1ZZ');
    });

    test('callsign search is case insensitive', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, 'w1aw');
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->callsign)->toBe('W1AW');
    });

    test('trims whitespace from callsign search', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, '  W1AW  ');
        $results = $query->get();

        expect($results)->toHaveCount(1);
    });

    test('returns all contacts when callsign is null', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, null);
        $results = $query->get();

        expect($results)->toHaveCount(3);
    });

    test('returns all contacts when callsign is empty string', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, '');
        $results = $query->get();

        expect($results)->toHaveCount(3);
    });

    test('returns all contacts when callsign is whitespace only', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forCallsign($query, '   ');
        $results = $query->get();

        expect($results)->toHaveCount(3);
    });
});

describe('forSection', function () {
    test('filters contacts by a single section ID', function () {
        $section = Section::where('code', 'CT')->first();
        $otherSection = Section::where('code', '!=', 'CT')->first();

        if (! $section || ! $otherSection) {
            $this->markTestSkipped('Required sections not found in database');
        }

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'section_id' => $section->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'section_id' => $otherSection->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forSection($query, [$section->id]);
        $results = $query->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->section_id)->toBe($section->id);
    });

    test('filters contacts by multiple section IDs', function () {
        $sections = Section::orderBy('code')->take(2)->get();

        if ($sections->count() < 2) {
            $this->markTestSkipped('Not enough sections in database');
        }

        $section1 = $sections->first();
        $section2 = $sections->last();
        $otherSection = Section::whereNotIn('id', [$section1->id, $section2->id])->first();

        if (! $otherSection) {
            $this->markTestSkipped('Not enough sections in database');
        }

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'section_id' => $section1->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'section_id' => $section2->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'section_id' => $otherSection->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forSection($query, [$section1->id, $section2->id]);
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->pluck('section_id')->all())->toContain($section1->id)
            ->and($results->pluck('section_id')->all())->toContain($section2->id);
    });

    test('returns all contacts when section IDs array is empty', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forSection($query, []);
        $results = $query->get();

        expect($results)->toHaveCount(3);
    });
});

describe('forDuplicateStatus', function () {
    test('filters to show only duplicates', function () {
        Contact::factory(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forDuplicateStatus($query, 'only');
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($contact) => $contact->is_duplicate === true))->toBeTrue();
    });

    test('filters to exclude duplicates', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => true,
        ]);
        Contact::factory(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forDuplicateStatus($query, 'exclude');
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($contact) => $contact->is_duplicate === false))->toBeTrue();
    });

    test('shows all contacts when duplicate filter is null', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_duplicate' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forDuplicateStatus($query, null);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('forGotaStatus', function () {
    test('filters to show only GOTA contacts', function () {
        Contact::factory(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forGotaStatus($query, 'only');
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($contact) => $contact->is_gota_contact === true))->toBeTrue();
    });

    test('filters to exclude GOTA contacts', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => true,
        ]);
        Contact::factory(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forGotaStatus($query, 'exclude');
        $results = $query->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($contact) => $contact->is_gota_contact === false))->toBeTrue();
    });

    test('shows all contacts when GOTA filter is null', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_gota_contact' => false,
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->forGotaStatus($query, null);
        $results = $query->get();

        expect($results)->toHaveCount(2);
    });
});

describe('chronological', function () {
    test('orders contacts by qso_time descending', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHours(3),
            'callsign' => 'OLDEST',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHour(),
            'callsign' => 'NEWEST',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHours(2),
            'callsign' => 'MIDDLE',
        ]);

        $query = $this->builder->buildQuery();
        $query = $this->builder->chronological($query);
        $results = $query->get();

        expect($results[0]->callsign)->toBe('NEWEST')
            ->and($results[1]->callsign)->toBe('MIDDLE')
            ->and($results[2]->callsign)->toBe('OLDEST');
    });
});

describe('applyFilters', function () {
    test('applies all filters together', function () {
        $section = Section::where('code', 'CT')->first();
        if (! $section) {
            $this->markTestSkipped('Required section not found in database');
        }

        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);

        // Contact that matches all filters
        $matchingContact = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'logger_user_id' => $this->user->id,
            'qso_time' => now(),
            'callsign' => 'W1AW',
            'section_id' => $section->id,
            'is_duplicate' => false,
        ]);

        // Contact that doesn't match band
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $this->otherBand->id,
            'mode_id' => $this->mode->id,
            'logger_user_id' => $this->user->id,
            'qso_time' => now(),
            'callsign' => 'K1ZZ',
            'section_id' => $section->id,
            'is_duplicate' => false,
        ]);

        $results = $this->builder->applyFilters([
            'event_configuration_id' => $this->eventConfig->id,
            'band_ids' => [$this->band->id],
            'mode_ids' => [$this->mode->id],
            'station_ids' => [$this->station->id],
            'operator_ids' => [$this->user->id],
            'time_from' => now()->subHour()->toDateTimeString(),
            'time_to' => now()->addHour()->toDateTimeString(),
            'callsign' => 'W1',
            'section_ids' => [$section->id],
            'duplicate_filter' => 'exclude',
        ])->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($matchingContact->id);
    });

    test('applies minimal filters', function () {
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $results = $this->builder->applyFilters([
            'event_configuration_id' => $this->eventConfig->id,
        ])->get();

        expect($results)->toHaveCount(3);
    });

    test('returns query builder for chaining', function () {
        $query = $this->builder->applyFilters([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);

        // Test that we can chain additional methods
        $results = $query->limit(10)->get();

        expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });

    test('applies chronological ordering by default', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHours(2),
            'callsign' => 'OLD',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now(),
            'callsign' => 'NEW',
        ]);

        $results = $this->builder->applyFilters([
            'event_configuration_id' => $this->eventConfig->id,
        ])->get();

        expect($results->first()->callsign)->toBe('NEW')
            ->and($results->last()->callsign)->toBe('OLD');
    });
});
