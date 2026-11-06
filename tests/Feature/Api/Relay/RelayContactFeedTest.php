<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\DTOs\RelayContactCursor;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Relay;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\RelayContactFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const FEED_TOKEN = 'fdca_feed-test-token';

beforeEach(function () {
    $this->freezeTime();

    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHours(3)]))
        ->create();
    $this->relay = Relay::factory()->withToken(FEED_TOKEN)->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Phone Tent',
    ]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Phone Tent']);
    $this->session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'K1ABC'])->id,
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function feedContact(array $attributes = []): Contact
{
    return Contact::factory()->create([
        'event_configuration_id' => test()->config->id,
        'operating_session_id' => test()->session->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $query
 */
function feed(array $query = []): TestResponse
{
    return test()->withToken(FEED_TOKEN)
        ->getJson('https://localhost/api/relay/v1/contacts?'.http_build_query($query))
        ->assertOk();
}

/**
 * Follow pages until the relay has caught up, returning every uuid delivered
 * in order and the cursor to poll with next.
 *
 * @return array{uuids: list<string>, cursor: string|null, pages: int}
 */
function feedRun(?string $cursor = null, int $limit = 100): array
{
    $uuids = [];
    $pages = 0;

    do {
        $response = feed(array_filter(['cursor' => $cursor, 'limit' => $limit]));
        $uuids = [...$uuids, ...array_column($response->json('contacts'), 'uuid')];
        $cursor = $response->json('next_cursor');
        $pages++;

        expect($pages)->toBeLessThan(50);
    } while ($response->json('has_more'));

    return ['uuids' => $uuids, 'cursor' => $cursor, 'pages' => $pages];
}

/**
 * Change a contact's timestamp without anything else, as a late commit looks.
 */
function stampContact(Contact $contact, DateTimeInterface $updatedAt): void
{
    Contact::withTrashed()->whereKey($contact->id)->toBase()->update(['updated_at' => $updatedAt]);
}

describe('first copy', function () {
    test('starts from the beginning, oldest change first', function () {
        $second = feedContact();
        $first = feedContact();
        stampContact($first, now()->subMinutes(10));
        stampContact($second, now()->subMinutes(5));

        feed()
            ->assertJsonCount(2, 'contacts')
            ->assertJsonPath('contacts.0.uuid', $first->uuid)
            ->assertJsonPath('contacts.1.uuid', $second->uuid)
            ->assertJsonPath('has_more', false);
    });

    test('an empty log still returns a cursor that finds the first contact', function () {
        $response = feed()->assertJsonPath('contacts', [])->assertJsonPath('has_more', false);

        $contact = feedContact();

        feed(['cursor' => $response->json('next_cursor')])->assertJsonPath('contacts.0.uuid', $contact->uuid);
    });

    test('includes contacts already deleted, so a relay starting over can remove them', function () {
        $contact = feedContact();
        $contact->delete();

        feed()->assertJsonPath('contacts.0.uuid', $contact->uuid)->assertJsonPath('contacts.0.deleted', true);
    });
});

describe('fields', function () {
    test('a contact reads back with the names and formats it was uploaded with', function () {
        $upload = [
            'idempotency_key' => 'contact-key-00000001',
            'callsign' => 'W1AW',
            'qso_time' => now()->utc()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'band' => '20m',
            'mode' => 'SSB',
            'frequency_hz' => 14250000,
            'operator' => 'K1ABC',
            'station' => 'Phone Tent',
            'exchange_class' => '3A',
            'section' => 'CT',
            'power_watts' => 100,
            'rst_sent' => '59',
            'rst_received' => '57',
            'grid_square' => 'FN31',
            'contact_name' => 'Hiram',
            'notes' => 'Nice signal',
        ];

        $this->withToken(FEED_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [$upload]])
            ->assertJsonPath('results.0.status', 'accepted');

        $entry = feed()->json('contacts.0');
        $contact = Contact::sole();

        expect($entry)->toMatchArray($upload)
            ->and($entry['uuid'])->toBe($contact->uuid)
            ->and($entry['deleted'])->toBeFalse()
            ->and($entry['updated_at'])->toBe($contact->updated_at->utc()->format('Y-m-d\TH:i:s\Z'));
    });

    test('doesn\'t expose N3FJP record numbers', function () {
        feedContact();
        Contact::query()->toBase()->update(['n3fjp_key' => 7]);

        expect(feed()->json('contacts.0'))->not->toHaveKey('n3fjp_key');
    });

    test('a contact logged by mode group gets a name that uploads back to the same group', function (string $group, string $expected) {
        $mode = Mode::firstOrCreate(['name' => $group], ['category' => $group, 'points_fd' => 1, 'points_wfd' => 1]);
        feedContact(['mode_id' => $mode->id, 'submode' => null]);

        feed()->assertJsonPath('contacts.0.mode', $expected);
    })->with([
        ['Phone', 'PH'],
        ['CW', 'CW'],
        ['Digital', 'DIG'],
    ]);

    test('empty fields are null rather than missing', function () {
        feedContact(['frequency_hz' => null, 'notes' => null, 'band_id' => null, 'section_id' => null]);

        $entry = feed()->json('contacts.0');

        expect($entry)->toHaveKeys(['frequency_hz', 'notes', 'band', 'section'])
            ->and($entry['band'])->toBeNull()
            ->and($entry['notes'])->toBeNull();
    });

    test('a contact keeps its station and operator names after they are deleted', function () {
        feedContact();
        $this->session->operator->delete();
        $this->station->delete();

        feed()
            ->assertJsonPath('contacts.0.station', 'Phone Tent')
            ->assertJsonPath('contacts.0.operator', 'K1ABC');
    });
});

describe('paging', function () {
    test('pages through every contact exactly once when nothing is recent', function () {
        $contacts = collect(range(1, 5))->map(function (int $minutes) {
            $contact = feedContact();
            stampContact($contact, now()->subMinutes(20 - $minutes));

            return $contact->uuid;
        })->all();

        $run = feedRun(limit: 2);

        expect($run['uuids'])->toBe($contacts)->and($run['pages'])->toBe(3);
        feed(['cursor' => $run['cursor']])->assertJsonPath('contacts', []);
    });

    test('moves on when more contacts than the limit share one second', function () {
        $sameSecond = now()->subMinutes(10);
        $contacts = collect(range(1, 7))->map(function () use ($sameSecond) {
            $contact = feedContact();
            stampContact($contact, $sameSecond);

            return $contact->uuid;
        })->all();

        expect(feedRun(limit: 3)['uuids'])->toBe($contacts);
    });

    test('moves on when more recent contacts than the limit share one second', function () {
        $contacts = collect(range(1, 7))->map(fn () => feedContact()->uuid)->all();

        expect(feedRun(limit: 3)['uuids'])->toBe($contacts);
    });

    test('uses the default limit and refuses limits out of range', function () {
        Contact::factory()->count(RelayContactFeedService::DEFAULT_LIMIT + 1)->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
        ]);

        feed()->assertJsonCount(RelayContactFeedService::DEFAULT_LIMIT, 'contacts')->assertJsonPath('has_more', true);

        foreach ([0, RelayContactFeedService::MAX_LIMIT + 1, 'many'] as $limit) {
            $this->withToken(FEED_TOKEN)->getJson('https://localhost/api/relay/v1/contacts?limit='.$limit)->assertUnprocessable();
        }
    });

    test('refuses a cursor this server didn\'t issue', function (string $cursor) {
        $this->withToken(FEED_TOKEN)
            ->getJson('https://localhost/api/relay/v1/contacts?cursor='.urlencode($cursor))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_cursor');
    })->with([
        'not base64' => ['!!!'],
        'not a cursor' => [base64_encode('hello')],
        'impossible date' => [base64_encode('2026-99-99 00:00:00|1')],
        'before the epoch' => [base64_encode('0000-01-01 00:00:00|1')],
        'impossible second time' => [base64_encode('2026-01-01 00:00:00|1|2026-13-01 00:00:00')],
        'non-numeric id' => [base64_encode('2026-01-01 00:00:00|x')],
    ]);

    test('a cursor survives encoding, including its rewind point', function () {
        $cursor = new RelayContactCursor('2026-06-27 18:05:00', 42, '2026-06-27 18:04:00');

        expect(RelayContactCursor::decode($cursor->encode()))->toEqual($cursor);
    });
});

describe('never missing a change', function () {
    test('repeats a recent contact until it is older than the overlap, then stops', function () {
        $contact = feedContact();

        $first = feed()->assertJsonPath('contacts.0.uuid', $contact->uuid);

        $this->travel(5)->seconds();
        $second = feed(['cursor' => $first->json('next_cursor')])->assertJsonPath('contacts.0.uuid', $contact->uuid);

        $this->travel(RelayContactFeedService::OVERLAP_SECONDS)->seconds();
        $settled = feed(['cursor' => $second->json('next_cursor')])->assertJsonPath('contacts.0.uuid', $contact->uuid);

        $resting = feed(['cursor' => $settled->json('next_cursor')])->assertJsonPath('contacts', []);

        expect($resting->json('next_cursor'))->toBe($settled->json('next_cursor'));
    });

    test('finds a change that commits late with a timestamp the relay has passed', function () {
        feedContact();
        $cursor = feed()->json('next_cursor');

        $this->travel(5)->seconds();
        $late = feedContact();
        stampContact($late, now()->subSeconds(30));
        feedContact();

        expect(feedRun($cursor)['uuids'])->toContain($late->uuid);
    });

    test('finds a late commit sharing a second with a contact already delivered', function () {
        $delivered = feedContact();
        $cursor = feed()->json('next_cursor');

        $this->travel(1)->seconds();
        $late = feedContact();
        stampContact($late, $delivered->updated_at);

        expect(feedRun($cursor)['uuids'])->toContain($late->uuid);
    });

    test('finds a late commit behind a page when the run outlasts the overlap', function () {
        $stamp = now();
        feedContact();
        feedContact();

        $firstPage = feed(['limit' => 1])->assertJsonPath('has_more', true);

        // Stamped just before the page's row, so the page's cursor is past it.
        $late = feedContact();
        stampContact($late, $stamp->copy()->subSecond());

        $this->travel(RelayContactFeedService::OVERLAP_SECONDS * 2)->seconds();

        $lastPage = feed(['cursor' => $firstPage->json('next_cursor'), 'limit' => 100])->assertJsonPath('has_more', false);

        expect(array_column($lastPage->json('contacts'), 'uuid'))->not->toContain($late->uuid)
            ->and(feedRun($lastPage->json('next_cursor'))['uuids'])->toContain($late->uuid);
    });

    test('a page that only reaches settled contacts doesn\'t come back to them', function () {
        foreach ([40, 30] as $minutes) {
            stampContact($contact = feedContact(), now()->subMinutes($minutes));
        }

        $firstPage = feed(['limit' => 1])->assertJsonPath('has_more', true);
        $lastPage = feed(['cursor' => $firstPage->json('next_cursor')])->assertJsonPath('contacts.0.uuid', $contact->uuid);

        feed(['cursor' => $lastPage->json('next_cursor')])->assertJsonPath('contacts', []);
    });

    test('recovers when the server clock is set back past the cursor', function () {
        feedContact();
        $this->travel(1)->hours();
        $cursor = feedRun()['cursor'];
        $cursor = feedRun($cursor)['cursor'];

        $this->travel(-2)->hours();
        $afterClockChange = feedContact();
        $cursor = feedRun($cursor)['cursor'];

        expect(feedRun($cursor)['uuids'])->toContain($afterClockChange->uuid);
    });
});

describe('edits and deletes', function () {
    test('an edit sends the contact again with its new values', function () {
        $contact = feedContact(['callsign' => 'W1AW']);
        stampContact($contact, now()->subMinutes(10));
        $cursor = feedRun()['cursor'];

        $contact->refresh()->update(['callsign' => 'W1XX']);

        feed(['cursor' => $cursor])
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.uuid', $contact->uuid)
            ->assertJsonPath('contacts.0.callsign', 'W1XX');
    });

    test('a delete sends only the deleted entry\'s fields', function () {
        $contact = feedContact();
        stampContact($contact, now()->subMinutes(10));
        $cursor = feedRun()['cursor'];

        $contact->refresh()->delete();

        expect(feed(['cursor' => $cursor])->json('contacts.0'))->toBe([
            'uuid' => $contact->uuid,
            'deleted' => true,
            'updated_at' => $contact->refresh()->updated_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    });

    test('a delete of an uploaded contact includes its idempotency key', function () {
        $contact = feedContact(['external_id' => 'relay:contact-key-00000001', 'external_source' => 'relay']);
        $contact->delete();

        feed()->assertJsonPath('contacts.0.deleted', true)->assertJsonPath('contacts.0.idempotency_key', 'contact-key-00000001');
    });

    test('a restored contact comes back as live', function () {
        $contact = feedContact();
        $contact->delete();
        stampContact($contact, now()->subMinutes(10));
        $cursor = feedRun()['cursor'];

        $contact->refresh()->restore();

        feed(['cursor' => $cursor])
            ->assertJsonPath('contacts.0.uuid', $contact->uuid)
            ->assertJsonPath('contacts.0.deleted', false)
            ->assertJsonPath('contacts.0.callsign', $contact->callsign);
    });
});

describe('idempotency key', function () {
    test('is the upload key for contacts from relays, including this relay\'s own', function () {
        $this->withToken(FEED_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [[
            'idempotency_key' => 'contact-key-00000001',
            'callsign' => 'W1AW',
            'qso_time' => now()->utc()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'band' => '20m',
            'mode' => 'SSB',
        ]]])->assertJsonPath('results.0.status', 'accepted');

        feed()->assertJsonPath('contacts.0.idempotency_key', 'contact-key-00000001');
    });

    test('follows an edit that changes the key', function () {
        $contact = [
            'idempotency_key' => 'contact-key-00000001',
            'callsign' => 'W1AW',
            'qso_time' => now()->utc()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'band' => '20m',
            'mode' => 'SSB',
        ];
        $edit = [...$contact, 'idempotency_key' => 'contact-key-00000002', 'replaces_key' => 'contact-key-00000001', 'callsign' => 'W1XX'];

        $this->withToken(FEED_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [$contact, $edit]])
            ->assertJsonPath('results.1.status', 'accepted');

        feed()->assertJsonCount(1, 'contacts')->assertJsonPath('contacts.0.idempotency_key', 'contact-key-00000002');
    });

    test('is null for contacts from anywhere else', function (?string $externalId) {
        feedContact(['external_id' => $externalId]);

        $entry = feed()->json('contacts.0');

        expect($entry)->toHaveKey('idempotency_key')->and($entry['idempotency_key'])->toBeNull();
    })->with([
        'logged in the app' => [null],
        'from another logger' => ['abc123relay:def'],
    ]);
});

describe('access', function () {
    test('only returns the event the token belongs to', function () {
        $mine = feedContact();
        $otherConfig = EventConfiguration::factory()->create();
        $theirs = Contact::factory()->create(['event_configuration_id' => $otherConfig->id]);
        $theirs->delete();
        Contact::factory()->create(['event_configuration_id' => $otherConfig->id]);

        expect(array_column(feed()->json('contacts'), 'uuid'))->toBe([$mine->uuid]);
    });

    test('a revoked token gets the same 401 as the other endpoints', function () {
        $this->relay->update(['revoked_at' => now()]);

        $handshake = $this->withToken(FEED_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertUnauthorized();

        $this->withToken(FEED_TOKEN)->getJson('https://localhost/api/relay/v1/contacts')
            ->assertUnauthorized()
            ->assertExactJson($handshake->json());
    });

    test('a missing token gets a 401', function () {
        $this->getJson('https://localhost/api/relay/v1/contacts')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    });

    test('plain HTTP from another computer is refused', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->withToken(FEED_TOKEN)
            ->getJson('http://192.168.1.50/api/relay/v1/contacts')
            ->assertForbidden()
            ->assertJsonPath('code', 'https_required');
    });
});

describe('capability', function () {
    test('the handshake says the feed is available', function () {
        $this->withToken(FEED_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')
            ->assertOk()
            ->assertJsonPath('capabilities.contact_feed', true)
            ->assertJsonStructure(['server_version', 'supported_api_versions', 'minimum_relay_version', 'server_time', 'relay', 'event', 'permissions', 'stations']);
    });
});

describe('cost', function () {
    function queriesForFeed(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        feed();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    test('the query count doesn\'t grow with the page', function () {
        feedContact();
        feed();
        $withOne = queriesForFeed();

        foreach (range(1, 20) as $number) {
            $station = Station::factory()->create(['event_configuration_id' => $this->config->id]);
            feedContact(['operating_session_id' => OperatingSession::factory()->create(['station_id' => $station->id])->id]);
        }

        expect(queriesForFeed())->toBe($withOne);
    });

    test('a poll with nothing new runs one query on the contacts table', function () {
        $cursor = feed()->json('next_cursor');

        DB::flushQueryLog();
        DB::enableQueryLog();
        feed(['cursor' => $cursor]);
        $contactQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from\W+contacts\W/i', $query['query']) === 1);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($contactQueries)->toHaveCount(1)->and($total)->toBeLessThan(10);
    });
});

describe('uuids', function () {
    test('a contact saved without a uuid gets one', function () {
        $contact = new Contact(Contact::factory()->raw([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $this->session->id,
            'uuid' => null,
        ]));

        $contact->save();

        expect(Str::isUuid($contact->uuid))->toBeTrue();
    });

    test('a uuid given by the browser is kept', function () {
        $uuid = Str::uuid()->toString();

        expect(feedContact(['uuid' => $uuid])->uuid)->toBe($uuid);
    });

    test('the backfill fills missing uuids without changing updated_at', function () {
        $contact = feedContact();
        stampContact($contact, now()->subDay());
        $updatedAt = $contact->refresh()->updated_at;
        Contact::query()->toBase()->update(['uuid' => null]);

        (require database_path('migrations/2026_11_06_000000_backfill_contact_uuids.php'))->up();

        $contact->refresh();

        expect(Str::isUuid($contact->uuid))->toBeTrue()
            ->and($contact->updated_at->equalTo($updatedAt))->toBeTrue();
    });
});
