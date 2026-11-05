<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\User;
use App\Services\AgentEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const AGENT_TOKEN = 'fdca_test-token';

beforeEach(function () {
    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'SSB'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->config = EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]))
        ->create();
    $this->agent = Agent::factory()->withToken(AGENT_TOKEN)->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Phone Tent',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agentContact(array $overrides = []): array
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

describe('https', function () {
    test('every agent route refuses plain HTTP', function (string $method, string $path) {
        $this->withToken(AGENT_TOKEN)
            ->json($method, 'http://localhost/api/agent/v1/'.$path, ['code' => 'WRONG', 'contacts' => [agentContact()]])
            ->assertForbidden()
            ->assertJsonPath('code', 'https_required');

        expect(Contact::count())->toBe(0);
    })->with([
        ['POST', 'enroll'],
        ['GET', 'handshake'],
        ['POST', 'contacts'],
    ]);

    test('a client outside the trusted proxies can\'t claim to be on HTTPS', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withToken(AGENT_TOKEN)
            ->getJson('http://localhost/api/agent/v1/handshake')
            ->assertForbidden();
    });

    test('a trusted TLS proxy counts as HTTPS', function () {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->withToken(AGENT_TOKEN)
            ->getJson('http://localhost/api/agent/v1/handshake')
            ->assertOk();
    });
});

describe('enrollment', function () {
    test('a code is traded once for a working token', function () {
        ['code' => $code] = app(AgentEnrollmentService::class)->issue($this->config, 'Logging PC');

        $response = $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => strtolower(str_replace('-', ' ', $code)), 'agent_version' => '26.11.1'])
            ->assertCreated()
            ->assertJsonPath('agent.name', 'Logging PC');

        $this->withToken($response->json('token'))->getJson('https://localhost/api/agent/v1/handshake')->assertOk();

        expect(Agent::where('name', 'Logging PC')->sole())
            ->agent_version->toBe('26.11.1')
            ->enrollment_code_hash->toBeNull()
            ->token_hash->toBe(Agent::hashSecret($response->json('token')));

        $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_code');
    });

    test('an expired code does not work', function () {
        ['code' => $code] = app(AgentEnrollmentService::class)->issue($this->config, 'Logging PC');

        $this->travel(AgentEnrollmentService::CODE_LIFETIME_MINUTES + 1)->minutes();

        $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => $code])->assertUnprocessable();
    });

    test('a revoked code does not work', function () {
        $service = app(AgentEnrollmentService::class);
        ['agent' => $agent, 'code' => $code] = $service->issue($this->config, 'Logging PC');

        $service->revoke($agent);

        $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => $code])->assertUnprocessable();
    });

    test('enrollment attempts are rate limited', function () {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => 'WRONG'])->assertUnprocessable();
        }

        $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => 'WRONG'])->assertTooManyRequests();
    });

    test('enrollment is audited under the person who created the code, without the secrets', function () {
        $user = User::factory()->create();
        ['agent' => $agent, 'code' => $code] = app(AgentEnrollmentService::class)->issue($this->config, 'Logging PC', $user->id);

        $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => 'WRONG'])->assertUnprocessable();
        $token = $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => $code, 'agent_version' => '26.11.1'])->json('token');

        $issued = AuditLog::where('action', 'agent.code_issued')->sole();
        $enrolled = AuditLog::where('action', 'agent.enrolled')->sole();

        expect($issued->user_id)->toBe($user->id)
            ->and($issued->auditable_id)->toBe($agent->id)
            ->and($enrolled->user_id)->toBe($user->id)
            ->and($enrolled->auditable_id)->toBe($agent->id)
            ->and($enrolled->new_values)->toMatchArray(['name' => 'Logging PC', 'agent_version' => '26.11.1'])
            ->and(AuditLog::where('action', 'agent.enrollment_failed')->count())->toBe(1);

        $logged = AuditLog::where('action', 'like', 'agent.%')->get()->toJson();

        expect($logged)->not->toContain($code)->not->toContain($token);
    });
});

describe('authentication', function () {
    test('requests without a valid token get JSON 401s', function (?string $token) {
        $request = $token === null ? $this : $this->withToken($token);

        $request->get('https://localhost/api/agent/v1/handshake')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    })->with([null, 'fdca_wrong']);

    test('a revoked agent is refused', function () {
        app(AgentEnrollmentService::class)->revoke($this->agent);

        $this->withToken(AGENT_TOKEN)->getJson('https://localhost/api/agent/v1/handshake')->assertUnauthorized();
    });

    test('an agent whose event was deleted is refused', function () {
        $this->config->delete();

        $this->withToken(AGENT_TOKEN)->getJson('https://localhost/api/agent/v1/handshake')->assertUnauthorized();
    });

    test('an unknown route under the API returns JSON, not a page', function () {
        $this->get('https://localhost/api/agent/v1/nope')->assertNotFound()->assertHeader('content-type', 'application/json');
    });

    test('contact requests are rate limited per agent', function () {
        foreach (range(1, 120) as $attempt) {
            $this->withToken(AGENT_TOKEN)->getJson('https://localhost/api/agent/v1/handshake')->assertOk();
        }

        $this->withToken(AGENT_TOKEN)->getJson('https://localhost/api/agent/v1/handshake')->assertTooManyRequests();

        $other = Agent::factory()->withToken('fdca_other-token')->create(['event_configuration_id' => $this->config->id]);
        $this->withToken('fdca_other-token')->getJson('https://localhost/api/agent/v1/handshake')->assertOk()->assertJsonPath('agent.id', $other->id);
    });
});

describe('handshake', function () {
    test('reports versions and the agent\'s event, and records the agent\'s version', function () {
        $this->withToken(AGENT_TOKEN)
            ->withHeader('X-Agent-Version', '26.11.2')
            ->getJson('https://localhost/api/agent/v1/handshake')
            ->assertOk()
            ->assertJsonPath('server_version', config('app.version'))
            ->assertJsonPath('supported_api_versions', [1])
            ->assertJsonPath('minimum_agent_version', null)
            ->assertJsonPath('agent.name', 'Phone Tent')
            ->assertJsonPath('event.name', $this->config->event->name);

        expect($this->agent->refresh())
            ->agent_version->toBe('26.11.2')
            ->last_seen_at->not->toBeNull();
    });
});

describe('contacts', function () {
    test('stores a contact against the agent\'s event and station', function () {
        $response = $this->withToken(AGENT_TOKEN)
            ->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [agentContact(['power_watts' => 5, 'frequency_hz' => 14_250_000, 'rst_sent' => '59'])]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        $contact = Contact::sole();

        expect($contact)
            ->uuid->toBe($response->json('results.0.contact_uuid'))
            ->event_configuration_id->toBe($this->config->id)
            ->callsign->toBe('W1AW')
            ->exchange_class->toBe('3A')
            ->external_source->toBe('agent')
            ->frequency_hz->toBe(14_250_000)
            ->rst_sent->toBe('59')
            ->power_watts->toBe(5);
        expect($contact->operatingSession->station->name)->toBe('Phone Tent');
    });

    test('accepts a time with a UTC offset', function () {
        $qsoTime = now()->setTimezone('-05:00')->startOfSecond();

        $this->withToken(AGENT_TOKEN)
            ->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [agentContact(['qso_time' => $qsoTime->format('Y-m-d\TH:i:sP')])]])
            ->assertJsonPath('results.0.status', 'accepted');

        expect(Contact::sole()->qso_time->getTimestamp())->toBe($qsoTime->getTimestamp());
    });

    test('sending the same key again stores one contact', function () {
        $send = fn (array $overrides = []) => $this->withToken(AGENT_TOKEN)
            ->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [agentContact($overrides)]])
            ->assertJsonPath('results.0.status', 'accepted');

        $uuid = $send()->json('results.0.contact_uuid');

        $send(['callsign' => 'K1ABC'])->assertJsonPath('results.0.contact_uuid', $uuid);

        expect(Contact::sole()->callsign)->toBe('K1ABC');
    });

    test('a contact deleted on the server stays deleted when sent again', function () {
        $send = fn () => $this->withToken(AGENT_TOKEN)
            ->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [agentContact()]])
            ->assertJsonPath('results.0.status', 'accepted');

        $send();
        Contact::sole()->delete();
        $send();

        expect(Contact::count())->toBe(0);
    });

    test('one bad contact does not stop the rest of the batch', function () {
        $this->withToken(AGENT_TOKEN)->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [
            agentContact(['idempotency_key' => 'short']),
            agentContact(['idempotency_key' => 'contact-key-00000002', 'qso_time' => '2026-06-27 18:05']),
            agentContact(['idempotency_key' => 'contact-key-00000003', 'callsign' => 'W1 AW!']),
            agentContact(['idempotency_key' => 'contact-key-00000004', 'qso_time' => now()->subDays(2)->utc()->format('Y-m-d\TH:i:s\Z')]),
            agentContact(['idempotency_key' => 'contact-key-00000005']),
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
        $contact = agentContact();
        $lock = Cache::lock("agent-contact:{$this->config->id}:agent:{$contact['idempotency_key']}", 30);
        $lock->get();

        $this->withToken(AGENT_TOKEN)->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => [$contact]])
            ->assertJsonPath('results.0.status', 'retry')
            ->assertJsonPath('results.0.code', 'busy');

        $lock->release();
        expect(Contact::count())->toBe(0);
    });

    test('a malformed batch is refused', function (mixed $contacts) {
        $this->withToken(AGENT_TOKEN)->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => $contacts])->assertUnprocessable();
    })->with([
        'missing' => [null],
        'empty' => [[]],
        'not a list' => [['a' => ['callsign' => 'W1AW']]],
        'not contacts' => [['x']],
    ]);

    test('a batch over the limit is refused', function () {
        $this->withToken(AGENT_TOKEN)
            ->postJson('https://localhost/api/agent/v1/contacts', ['contacts' => array_fill(0, 101, ['callsign' => 'W1AW'])])
            ->assertUnprocessable();
    });
});

describe('commands', function () {
    test('issue-code creates an agent for the given event, with a code that enrolls it', function () {
        $this->artisan('agent:issue-code', ['name' => 'Logging PC', '--event' => $this->config->id])
            ->expectsOutputToContain('Enter it in the agent')
            ->assertSuccessful();

        expect(Agent::where('name', 'Logging PC')->sole())
            ->event_configuration_id->toBe($this->config->id)
            ->enrollment_code_hash->not->toBeNull()
            ->token_hash->toBeNull();
    });

    test('issue-code fails for an unknown event', function () {
        $this->artisan('agent:issue-code', ['name' => 'Logging PC', '--event' => 999999])->assertFailed();
    });

    test('revoke stops the agent\'s token from working', function () {
        $this->artisan('agent:revoke', ['agent' => $this->agent->id])->assertSuccessful();

        $this->withToken(AGENT_TOKEN)->getJson('https://localhost/api/agent/v1/handshake')->assertUnauthorized();
    });

    test('revoke without an ID lists agents and changes nothing', function () {
        $this->artisan('agent:revoke')->expectsOutputToContain('Phone Tent')->assertSuccessful();

        expect($this->agent->refresh()->revoked_at)->toBeNull();
    });
});

test('the OpenAPI contract lists exactly the routes that exist', function () {
    $spec = Yaml::parseFile(resource_path('api/agent-v1.openapi.yaml'));

    $documented = collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method): string => strtoupper($method).' api/agent/v1'.$path))
        ->sort()->values()->all();

    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/agent/v1/'))
        ->flatMap(fn (LaravelRoute $route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri()))
        ->sort()->values()->all();

    expect($documented)->toBe($actual);
});
