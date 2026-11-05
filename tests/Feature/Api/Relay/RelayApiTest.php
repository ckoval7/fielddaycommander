<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Relay;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\RelayEnrollmentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const RELAY_TOKEN = 'fdca_test-token';

beforeEach(function () {
    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'SSB'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]))
        ->create();
    $this->relay = Relay::factory()->withToken(RELAY_TOKEN)->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Phone Tent',
    ]);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Phone Tent']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function relayContact(array $overrides = []): array
{
    return [
        'idempotency_key' => 'contact-key-00000001',
        'callsign' => 'w1aw',
        'qso_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'band' => '20m',
        'mode' => 'SSB',
        'section' => 'ct',
        'exchange_class' => '3a',
        ...$overrides,
    ];
}

function enrollAs(string $role, Relay $relay): User
{
    $user = User::factory()->create()->assignRole($role);
    $relay->update(['created_by_user_id' => $user->id]);

    return $user;
}

describe('https', function () {
    test('every relay route refuses plain HTTP from another computer', function (string $method, string $path) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->withToken(RELAY_TOKEN)
            ->json($method, 'http://192.168.1.50/api/relay/v1/'.$path, ['code' => 'WRONG', 'contacts' => [relayContact()]])
            ->assertForbidden()
            ->assertJsonPath('code', 'https_required');

        expect(Contact::count())->toBe(0);
    })->with([
        ['POST', 'enroll'],
        ['GET', 'handshake'],
        ['POST', 'contacts'],
    ]);

    test('plain HTTP works from the server itself over localhost', function (string $host, string $address) {
        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->withToken(RELAY_TOKEN)
            ->getJson("http://{$host}/api/relay/v1/handshake")
            ->assertOk();
    })->with([
        ['localhost', '127.0.0.1'],
        ['127.0.0.1', '127.0.0.1'],
        ['[::1]', '::1'],
    ]);

    test('another computer can\'t pass as localhost with a forged Host header', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->withToken(RELAY_TOKEN)
            ->getJson('http://localhost/api/relay/v1/handshake')
            ->assertForbidden();
    });

    test('a request forwarded by a proxy on the same machine isn\'t localhost', function (string $host) {
        $this->withHeader('X-Forwarded-For', '192.168.1.20')
            ->withToken(RELAY_TOKEN)
            ->getJson("http://{$host}/api/relay/v1/handshake")
            ->assertForbidden();
    })->with(['localhost', '192.168.1.50']);

    test('a loopback sender reaching the server by a LAN address isn\'t localhost', function () {
        $this->withToken(RELAY_TOKEN)->getJson('http://192.168.1.50/api/relay/v1/handshake')->assertForbidden();
    });

    test('a client outside the trusted proxies can\'t claim to be on HTTPS', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withToken(RELAY_TOKEN)
            ->getJson('http://192.168.1.50/api/relay/v1/handshake')
            ->assertForbidden();
    });

    test('a trusted TLS proxy counts as HTTPS', function () {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '192.168.1.20'])
            ->withToken(RELAY_TOKEN)
            ->getJson('http://192.168.1.50/api/relay/v1/handshake')
            ->assertOk();
    });
});

describe('enrollment', function () {
    test('a code is traded once for a working token', function () {
        ['code' => $code] = app(RelayEnrollmentService::class)->issue($this->config, 'Logging PC');

        $response = $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => strtolower(str_replace('-', ' ', $code)), 'relay_version' => '26.11.1'])
            ->assertCreated()
            ->assertJsonPath('relay.name', 'Logging PC');

        $this->withToken($response->json('token'))->getJson('https://localhost/api/relay/v1/handshake')->assertOk();

        expect(Relay::where('name', 'Logging PC')->sole())
            ->relay_version->toBe('26.11.1')
            ->enrollment_code_hash->toBeNull()
            ->token_hash->toBe(Relay::hashSecret($response->json('token')));

        $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_code');
    });

    test('an expired code does not work', function () {
        ['code' => $code] = app(RelayEnrollmentService::class)->issue($this->config, 'Logging PC');

        $this->travel(RelayEnrollmentService::CODE_LIFETIME_MINUTES + 1)->minutes();

        $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => $code])->assertUnprocessable();
    });

    test('a revoked code does not work', function () {
        $service = app(RelayEnrollmentService::class);
        ['relay' => $relay, 'code' => $code] = $service->issue($this->config, 'Logging PC');

        $service->revoke($relay);

        $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => $code])->assertUnprocessable();
    });

    test('enrollment attempts are rate limited', function () {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => 'WRONG'])->assertUnprocessable();
        }

        $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => 'WRONG'])->assertTooManyRequests();
    });

    test('enrollment is audited under the person who created the code, without the secrets', function () {
        $user = User::factory()->create();
        ['relay' => $relay, 'code' => $code] = app(RelayEnrollmentService::class)->issue($this->config, 'Logging PC', $user->id);

        $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => 'WRONG'])->assertUnprocessable();
        $token = $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => $code, 'relay_version' => '26.11.1'])->json('token');

        $issued = AuditLog::where('action', 'relay.code_issued')->sole();
        $enrolled = AuditLog::where('action', 'relay.enrolled')->sole();

        expect($issued->user_id)->toBe($user->id)
            ->and($issued->auditable_id)->toBe($relay->id)
            ->and($enrolled->user_id)->toBe($user->id)
            ->and($enrolled->auditable_id)->toBe($relay->id)
            ->and($enrolled->new_values)->toMatchArray(['name' => 'Logging PC', 'relay_version' => '26.11.1'])
            ->and(AuditLog::where('action', 'relay.enrollment_failed')->count())->toBe(1);

        $logged = AuditLog::where('action', 'like', 'relay.%')->get()->toJson();

        expect($logged)->not->toContain($code)->not->toContain($token);
    });
});

describe('authentication', function () {
    test('requests without a valid token get JSON 401s', function (?string $token) {
        $request = $token === null ? $this : $this->withToken($token);

        $request->get('https://localhost/api/relay/v1/handshake')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    })->with([null, 'fdca_wrong']);

    test('a revoked relay is refused', function () {
        app(RelayEnrollmentService::class)->revoke($this->relay);

        $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertUnauthorized();
    });

    test('a relay whose event was deleted is refused', function () {
        $this->config->delete();

        $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertUnauthorized();
    });

    test('an unknown route under the API returns JSON, not a page', function () {
        $this->get('https://localhost/api/relay/v1/nope')->assertNotFound()->assertHeader('content-type', 'application/json');
    });

    test('contact requests are rate limited per relay', function () {
        foreach (range(1, 120) as $attempt) {
            $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertOk();
        }

        $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertTooManyRequests();

        $other = Relay::factory()->withToken('fdca_other-token')->create(['event_configuration_id' => $this->config->id]);
        $this->withToken('fdca_other-token')->getJson('https://localhost/api/relay/v1/handshake')->assertOk()->assertJsonPath('relay.id', $other->id);
    });
});

describe('handshake', function () {
    test('reports versions and the relay\'s event, and records the relay\'s version', function () {
        $this->withToken(RELAY_TOKEN)
            ->withHeader('X-Relay-Version', '26.11.2')
            ->getJson('https://localhost/api/relay/v1/handshake')
            ->assertOk()
            ->assertJsonPath('server_version', config('app.version'))
            ->assertJsonPath('supported_api_versions', [1])
            ->assertJsonPath('minimum_relay_version', null)
            ->assertJsonPath('relay.name', 'Phone Tent')
            ->assertJsonPath('event.name', $this->config->event->name);

        expect($this->relay->refresh())
            ->relay_version->toBe('26.11.2')
            ->last_seen_at->not->toBeNull();
    });

    test('lists only this event\'s stations, sorted by name', function () {
        Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'CW Tent', 'hostname' => 'cw-laptop']);
        Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Removed'])->delete();
        Station::factory()->create(['name' => 'Other Event']);

        $this->withToken(RELAY_TOKEN)
            ->getJson('https://localhost/api/relay/v1/handshake')
            ->assertOk()
            ->assertJsonPath('stations.0', ['name' => 'CW Tent', 'hostname' => 'cw-laptop'])
            ->assertJsonPath('stations.1.name', 'Phone Tent')
            ->assertJsonCount(2, 'stations');
    });

    test('reports whether the relay can create stations', function () {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $handshake = fn () => $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake');

        $handshake()->assertJsonPath('permissions.create_stations', false);

        $user = enrollAs('Operator', $this->relay);
        $handshake()->assertJsonPath('permissions.create_stations', false);

        $user->syncRoles('Station Captain');
        $handshake()->assertJsonPath('permissions.create_stations', true);
    });
});

describe('contacts', function () {
    test('stores a contact against the relay\'s event and station', function () {
        $response = $this->withToken(RELAY_TOKEN)
            ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['power_watts' => 5, 'frequency_hz' => 14_250_000, 'rst_sent' => '59'])]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        $contact = Contact::sole();

        expect($contact)
            ->uuid->toBe($response->json('results.0.contact_uuid'))
            ->event_configuration_id->toBe($this->config->id)
            ->callsign->toBe('W1AW')
            ->exchange_class->toBe('3A')
            ->external_source->toBe('relay')
            ->frequency_hz->toBe(14_250_000)
            ->rst_sent->toBe('59')
            ->power_watts->toBe(5);
        expect($contact->operatingSession->station->name)->toBe('Phone Tent');
    });

    describe('unknown stations', function () {
        beforeEach(function () {
            $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        });

        test('are created when the person who enrolled the relay has manage-stations', function () {
            enrollAs('Station Captain', $this->relay);

            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['station' => 'New Tent'])]])
                ->assertOk()
                ->assertJsonPath('results.0.status', 'accepted');

            expect(Station::where('name', 'New Tent')->exists())->toBeTrue();
        });

        test('wait in the relay\'s queue when that person doesn\'t', function () {
            enrollAs('Operator', $this->relay);

            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['station' => 'New Tent'])]])
                ->assertOk()
                ->assertJsonPath('results.0.status', 'retry')
                ->assertJsonPath('results.0.code', 'unknown_station');

            expect(Station::where('name', 'New Tent')->exists())->toBeFalse()
                ->and(Contact::count())->toBe(0);
        });

        test('wait the same way when the contact falls back to the relay\'s name', function () {
            enrollAs('Operator', $this->relay);
            Station::where('name', 'Phone Tent')->delete();

            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact()]])
                ->assertJsonPath('results.0.code', 'unknown_station');

            expect(Station::count())->toBe(0);
        });

        test('are stored on retry once someone creates the station', function () {
            enrollAs('Operator', $this->relay);
            $request = ['contacts' => [relayContact(['station' => 'New Tent'])]];

            $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', $request)
                ->assertJsonPath('results.0.status', 'retry');

            $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'New Tent']);

            $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', $request)
                ->assertJsonPath('results.0.status', 'accepted');

            expect(Contact::sole()->operatingSession->station_id)->toBe($station->id);
        });

        test('match an existing station by hostname without manage-stations', function () {
            enrollAs('Operator', $this->relay);
            Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'CW Tent', 'hostname' => 'cw-laptop']);

            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['station' => 'CW-LAPTOP'])]])
                ->assertJsonPath('results.0.status', 'accepted');

            expect(Station::count())->toBe(2);
        });

        test('stop being created when the person loses manage-stations', function () {
            $user = enrollAs('Station Captain', $this->relay);
            $user->syncRoles('Operator');

            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['station' => 'New Tent'])]])
                ->assertJsonPath('results.0.code', 'unknown_station');
        });

        test('aren\'t created by a relay with no enrolling person', function () {
            $this->withToken(RELAY_TOKEN)
                ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['station' => 'New Tent'])]])
                ->assertJsonPath('results.0.code', 'unknown_station');
        });

        test('don\'t block a repeat of a contact already stored', function () {
            enrollAs('Operator', $this->relay);
            $request = ['contacts' => [relayContact()]];

            $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', $request)
                ->assertJsonPath('results.0.status', 'accepted');
            Station::where('name', 'Phone Tent')->delete();

            $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', $request)
                ->assertJsonPath('results.0.status', 'accepted');

            expect(Station::withTrashed()->count())->toBe(1);
        });
    });

    test('accepts a time with a UTC offset', function () {
        $qsoTime = now()->setTimezone('-05:00')->startOfSecond();

        $this->withToken(RELAY_TOKEN)
            ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact(['qso_time' => $qsoTime->format('Y-m-d\TH:i:sP')])]])
            ->assertJsonPath('results.0.status', 'accepted');

        expect(Contact::sole()->qso_time->getTimestamp())->toBe($qsoTime->getTimestamp());
    });

    test('sending the same key again stores one contact', function () {
        $send = fn (array $overrides = []) => $this->withToken(RELAY_TOKEN)
            ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact($overrides)]])
            ->assertJsonPath('results.0.status', 'accepted');

        $uuid = $send()->json('results.0.contact_uuid');

        $send(['callsign' => 'K1ABC'])->assertJsonPath('results.0.contact_uuid', $uuid);

        expect(Contact::sole()->callsign)->toBe('K1ABC');
    });

    test('a contact deleted on the server stays deleted when sent again', function () {
        $send = fn () => $this->withToken(RELAY_TOKEN)
            ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [relayContact()]])
            ->assertJsonPath('results.0.status', 'accepted');

        $send();
        Contact::sole()->delete();
        $send();

        expect(Contact::count())->toBe(0);
    });

    test('one bad contact does not stop the rest of the batch', function () {
        $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [
            relayContact(['idempotency_key' => 'short']),
            relayContact(['idempotency_key' => 'contact-key-00000002', 'qso_time' => '2026-06-27 18:05']),
            relayContact(['idempotency_key' => 'contact-key-00000003', 'callsign' => 'W1 AW!']),
            relayContact(['idempotency_key' => 'contact-key-00000004', 'qso_time' => now()->subDays(2)->utc()->format('Y-m-d\TH:i:s\Z')]),
            relayContact(['idempotency_key' => 'contact-key-00000005']),
        ]])
            ->assertOk()
            ->assertJsonPath('results.0.code', 'invalid')
            ->assertJsonPath('results.1.code', 'invalid')
            ->assertJsonPath('results.2.code', 'invalid_contact')
            ->assertJsonPath('results.3.code', 'out_of_period')
            ->assertJsonPath('results.4.status', 'accepted')
            ->assertJsonPath('results.4.index', 4);

        expect(Contact::count())->toBe(1);
    });

    test('a contact being stored by another request is sent back for a retry', function () {
        $contact = relayContact();
        $lock = Cache::lock("relay-contact:{$this->config->id}:relay:{$contact['idempotency_key']}", 30);
        $lock->get();

        $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => [$contact]])
            ->assertJsonPath('results.0.status', 'retry')
            ->assertJsonPath('results.0.code', 'busy');

        $lock->release();
        expect(Contact::count())->toBe(0);
    });

    test('a malformed batch is refused', function (mixed $contacts) {
        $this->withToken(RELAY_TOKEN)->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => $contacts])->assertUnprocessable();
    })->with([
        'missing' => [null],
        'empty' => [[]],
        'not a list' => [['a' => ['callsign' => 'W1AW']]],
        'not contacts' => [['x']],
    ]);

    test('a batch over the limit is refused', function () {
        $this->withToken(RELAY_TOKEN)
            ->postJson('https://localhost/api/relay/v1/contacts', ['contacts' => array_fill(0, 101, ['callsign' => 'W1AW'])])
            ->assertUnprocessable();
    });
});

describe('commands', function () {
    test('issue-code creates a relay for the given event, with a code that enrolls it', function () {
        $this->artisan('relay:issue-code', ['name' => 'Logging PC', '--event' => $this->config->id])
            ->expectsOutputToContain('Enter it in the relay')
            ->assertSuccessful();

        expect(Relay::where('name', 'Logging PC')->sole())
            ->event_configuration_id->toBe($this->config->id)
            ->enrollment_code_hash->not->toBeNull()
            ->token_hash->toBeNull();
    });

    test('issue-code fails for an unknown event', function () {
        $this->artisan('relay:issue-code', ['name' => 'Logging PC', '--event' => 999999])->assertFailed();
    });

    test('revoke stops the relay\'s token from working', function () {
        $this->artisan('relay:revoke', ['relay' => $this->relay->id])->assertSuccessful();

        $this->withToken(RELAY_TOKEN)->getJson('https://localhost/api/relay/v1/handshake')->assertUnauthorized();
    });

    test('revoke without an ID lists relays and changes nothing', function () {
        $this->artisan('relay:revoke')->expectsOutputToContain('Phone Tent')->assertSuccessful();

        expect($this->relay->refresh()->revoked_at)->toBeNull();
    });
});

test('the OpenAPI contract lists exactly the routes that exist', function () {
    $spec = Yaml::parseFile(resource_path('api/relay-v1.openapi.yaml'));

    $documented = collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method): string => strtoupper($method).' api/relay/v1'.$path))
        ->sort()->values()->all();

    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/relay/v1/'))
        ->flatMap(fn (LaravelRoute $route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri()))
        ->sort()->values()->all();

    expect($documented)->toBe($actual);
});
